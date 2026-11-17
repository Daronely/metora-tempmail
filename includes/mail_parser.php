<?php
/**
 * Shared MIME mail parser + deliverer.
 * Used by BOTH cron/imap_poll.php and pipe/pipe.php so the two paths
 * can never drift apart.
 *
 * parse_raw_mail()  → structured array (subject/from/to/text/html/attachments)
 * deliver_mail()    → recipient lookup, bounce/daemon filtering, DB insert,
 *                     attachment storage, inbox-limit enforcement
 */

require_once __DIR__ . '/db.php';

/* ── RFC 2047 encoded-word decoder with imap fallback ── */
function mp_decode_header(string $s): string {
    if (function_exists('imap_mime_header_decode')) {
        $parts = imap_mime_header_decode($s);
        $out = '';
        foreach ((array)$parts as $p) $out .= $p->text;
        return trim($out);
    }
    // manual fallback: =?charset?B|Q?...?=
    return trim(preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
        fn($m) => $m[2] === 'B' || $m[2] === 'b'
            ? base64_decode($m[3])
            : quoted_printable_decode(str_replace('_', ' ', $m[3])), $s));
}

/* ── unfold + split raw headers into assoc array ── */
function mp_parse_headers(string $raw): array {
    $raw = preg_replace('/\r?\n[ \t]+/', ' ', $raw);   // unfold continuation lines
    $h = [];
    foreach (explode("\n", str_replace("\r\n", "\n", $raw)) as $line) {
        $c = strpos($line, ':');
        if ($c !== false) {
            $k = strtolower(trim(substr($line, 0, $c)));
            if ($k !== '' && !isset($h[$k])) $h[$k] = trim(substr($line, $c + 1));
        }
    }
    return $h;
}

/* ── decode a mime part body by its transfer-encoding ── */
function mp_decode_body(string $content, string $encoding): string {
    switch (strtolower(trim($encoding))) {
        case 'base64':     return base64_decode($content);
        case 'quoted-printable': return quoted_printable_decode($content);
        case '7bit':
        case '8bit':
        case 'binary':
        default:           return $content;
    }
}

/* ── extract a param (name/filename/charset/boundary) from a header value ── */
function mp_header_param(string $value, string $param): ?string {
    if (preg_match('/' . $param . '\s*=\s*"([^"]*)"/i', $value, $m)) return $m[1];
    if (preg_match('/' . $param . '\s*=\s*([^\s;]+)/i', $value, $m)) return trim($m[1]);
    return null;
}

/**
 * Parse a raw RFC822 message (headers + body) into structured data.
 * Handles multipart/mixed, multipart/alternative, nested parts,
 * base64 / quoted-printable, inline + attachment dispositions.
 */
function mp_parse_mail(string $raw): array {
    $split = strpos($raw, "\r\n\r\n"); $sep = 4;
    if ($split === false) { $split = strpos($raw, "\n\n"); $sep = 2; }
    if ($split === false) { $headersRaw = $raw; $bodyRaw = ''; }
    else { $headersRaw = substr($raw, 0, $split); $bodyRaw = substr($raw, $split + $sep); }

    $hdr = mp_parse_headers($headersRaw);
    $subject = mp_decode_header($hdr['subject'] ?? '');
    $from    = mp_decode_header($hdr['from'] ?? '');
    $to      = mp_decode_header($hdr['to'] ?? '');
    $msgId   = $hdr['message-id'] ?? '';

    $text = null; $html = null; $atts = [];
    $ctype = $hdr['content-type'] ?? 'text/plain';

    $walk = function (string $body, string $ctype, string $enc, string $dispo, string $depth) use (&$walk, &$text, &$html, &$atts) {
        // multipart? recurse into each sub-part
        if (stripos($ctype, 'multipart/') === 0) {
            $b = mp_header_param($ctype, 'boundary');
            if ($b) {
                $segments = explode('--' . $b, $body);
                array_shift($segments);                    // preamble
                foreach ($segments as $seg) {
                    if (rtrim($seg) === '--' || trim($seg) === '') continue;
                    $ps = strpos($seg, "\r\n\r\n"); $pl = 4;
                    if ($ps === false) { $ps = strpos($seg, "\n\n"); $pl = 2; }
                    if ($ps === false) continue;
                    $ph   = mp_parse_headers(substr($seg, 0, $ps));
                    $pc   = substr($seg, $ps + $pl);
                    $pct  = $ph['content-type'] ?? 'text/plain';
                    $penc = $ph['content-transfer-encoding'] ?? '';
                    $pd   = $ph['content-disposition'] ?? '';
                    // strip the trailing CRLF that belongs to the boundary
                    $walk(rtrim($pc, "\r\n"), $pct, $penc, $pd, $depth . '/');
                }
                return;
            }
        }
        // leaf part
        $isAtt = stripos($dispo, 'attachment') !== false;
        $fname = mp_header_param($dispo, 'filename') ?: mp_header_param($ctype, 'name');
        // keep the sender-declared MIME type for the attachments table
        $ptype = trim(preg_split('/[;\s]/', $ctype)[0]);
        if ($isAtt || ($fname !== null && stripos($ptype, 'text/plain') !== 0 && stripos($ptype, 'text/html') !== 0)) {
            $data = mp_decode_body($body, $enc);
            if ($data !== '' && strlen($data) <= MAX_UPLOAD_BYTES) {
                $atts[] = [
                    'filename' => $fname ?: ('file-' . count($atts)),
                    'data'     => $data,
                    'size'     => strlen($data),
                    'mime'     => $ptype ?: 'application/octet-stream',
                ];
            }
        } elseif (stripos($ptype, 'text/html') === 0) {
            if ($html === null) $html = mp_decode_body($body, $enc);
        } elseif (stripos($ptype, 'text/plain') === 0 || $ctype === '') {
            if ($text === null) $text = mp_decode_body($body, $enc);
        } else {
            // other inline content-type with a name → attachment
            $data = mp_decode_body($body, $enc);
            if ($fname && $data !== '' && strlen($data) <= MAX_UPLOAD_BYTES) {
                $atts[] = ['filename' => $fname, 'data' => $data, 'size' => strlen($data),
                           'mime' => $ptype ?: 'application/octet-stream'];
            }
        }
    };
    $walk($bodyRaw, $ctype, $hdr['content-transfer-encoding'] ?? '', $hdr['content-disposition'] ?? '', '');

    if ($text === null && $html === null) $text = '';
    // derive plain text from html when only html exists
    if ($text === null || $text === '') {
        if ($html) {
            $t = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
            $t = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $t);
            $t = preg_replace('/<br\s*\/?>/i', "\n", $t);
            $t = preg_replace('/<\/p>/i', "\n\n", $t);
            $t = strip_tags($t);
            $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            $text = trim($t);
        } else $text = (string)$text;
    }

    return [
        'headers' => $hdr, 'subject' => $subject, 'from' => $from, 'to' => $to,
        'msg_id'  => $msgId, 'text' => (string)$text, 'html' => $html,
        'attachments' => $atts, 'size' => strlen($raw),
    ];
}

/**
 * Is this a mailer-daemon / bounce / auto-generated message that should
 * never reach a user's inbox?
 */
function mp_is_daemon_mail(array $parsed): bool {
    $h = $parsed['headers'];
    $from = strtolower($parsed['from'] ?? '');
    $subj = $parsed['subject'] ?? '';

    // classic daemon senders (verification "noreply" senders are NOT this)
    if (preg_match('/^"?mailer-daemon|^postmaster@|^bounce@|^maildaemon@/i', trim($from))) return true;

    // delivery-status report containers
    if (isset($h['content-type']) && stripos($h['content-type'], 'multipart/report') === 0) return true;

    // explicit bounce subjects
    if (preg_match('/^(mail delivery failed|delivery status notification|undeliverable|returned mail|failure notice|delayed mail|delivery failure|mail delivery system)/i', trim($subj))) return true;

    // auto-responders (vacation etc.) — NOT transactional verification mail
    if (isset($h['x-autoreply']) || isset($h['x-autorespond'])) return true;
    if (isset($h['precedence']) && preg_match('/auto_reply/i', $h['precedence'])) return true;
    if (isset($h['x-failed-recipients'])) return true;

    // NOTE: Auto-Submitted: auto-generated is intentionally NOT filtered —
    // virtually every OTP/verification mail carries it (RFC 3834 allows it).
    return false;
}

/**
 * Deliver a parsed mail to the local account matching the envelope recipient.
 * Returns: 'ok' | 'unknown' | 'daemon' | 'error:<msg>'
 */
function mp_deliver(array $parsed, string $toEmail, string $rawForSize = ''): string {
    $pdo = db();
    $toEmail = strtolower(trim($toEmail));

    // find account by email (exact) or username local-part
    $stmt = $pdo->prepare("SELECT id, is_premium, role FROM accounts WHERE email = ? LIMIT 1");
    $stmt->execute([$toEmail]);
    $acc = $stmt->fetch();
    if (!$acc) {
        $local = explode('@', $toEmail)[0];
        $stmt = $pdo->prepare("SELECT id, is_premium, role FROM accounts WHERE username = ? LIMIT 1");
        $stmt->execute([$local]);
        $acc = $stmt->fetch();
    }
    if (!$acc) return 'unknown';

    if (mp_is_daemon_mail($parsed)) return 'daemon';

    /* ── REAL dedupe ─────────────────────────────────────────
       1) exact: same Message-ID already delivered to this account
       2) fuzzy: same from+subject within 5 minutes (covers mails
          without Message-ID and pipeline retries after crashes) */
    $mid = trim($parsed['msg_id'] ?? '');
    if ($mid !== '') {
        $stmt = $pdo->prepare("SELECT id FROM messages WHERE account_id = ? AND folder='inbox'
                               AND dedupe_key = ? LIMIT 1");
        $stmt->execute([$acc['id'], hash('sha256', $mid)]);
        if ($stmt->fetch()) return 'ok';   // already delivered
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM messages
                           WHERE account_id = ? AND folder='inbox' AND from_addr = ? AND subject = ?
                             AND created_at > NOW() - INTERVAL 5 MINUTE");
    $stmt->execute([$acc['id'], mb_substr($parsed['from'], 0, 255), mb_substr($parsed['subject'], 0, 500)]);
    if ((int)$stmt->fetch()['c'] > 0) return 'ok';   // already have it

    /* ── incoming attachment budget ──────────────────────────
       cap BOTH the count (10) and the total bytes (2×MAX_UPLOAD)
       per mail so a hostile multipart bomb can't fill the disk */
    $maxAtt = 10;
    $maxTotal = defined('MAX_UPLOAD_BYTES') ? MAX_UPLOAD_BYTES * 2 : 4194304;
    $atts = [];
    $total = 0;
    foreach ($parsed['attachments'] as $a) {
        if (count($atts) >= $maxAtt) break;
        if ($total + $a['size'] > $maxTotal) break;
        $atts[] = $a; $total += $a['size'];
    }

    $size = $parsed['size'] ?: strlen($rawForSize);
    $pdo->prepare("INSERT INTO messages (account_id, folder, from_addr, to_addr, subject, body_text, body_html, size_bytes, dedupe_key)
                   VALUES (?, 'inbox', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $acc['id'],
            mb_substr($parsed['from'], 0, 255),
            mb_substr($parsed['to'] ?: $toEmail, 0, 1000),
            mb_substr($parsed['subject'] ?: '(no subject)', 0, 500),
            $parsed['text'],
            $parsed['html'],
            (int)$size,
            $mid !== '' ? hash('sha256', $mid) : null,
        ]);
    $msgId = (int)$pdo->lastInsertId();

    foreach ($atts as $a) {
        $dir = STORAGE_DIR . '/att';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $rel = 'att/' . bin2hex(random_bytes(12)) . '-' . preg_replace('/[^\w.\- ]/u', '_', $a['filename']);
        if (file_put_contents(STORAGE_DIR . '/' . $rel, $a['data']) === false) continue;
        // prefer the MIME type declared by the sending mail client,
        // fall back to server-side sniffing, then generic binary
        $mime = $a['mime'] ?? '';
        if (!$mime || $mime === 'application/octet-stream') {
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $sniff = finfo_buffer($fi, $a['data']) ?: '';
                finfo_close($fi);
                if ($sniff) $mime = $sniff;
            }
        }
        if (!$mime) $mime = 'application/octet-stream';
        $pdo->prepare("INSERT INTO attachments (message_id, filename, mime, size_bytes, file_path) VALUES (?,?,?,?,?)")
            ->execute([$msgId, mb_substr($a['filename'], 0, 255), $mime, $a['size'], $rel]);
    }

    // enforce inbox limit (keep newest N)
    $limit = ($acc['role'] === 'admin') ? 100000
           : ($acc['is_premium'] ? (int)get_setting('inbox_limit_premium', INBOX_LIMIT_PREMIUM) : (int)get_setting('inbox_limit_free', INBOX_LIMIT_FREE));
    $pdo->prepare("DELETE FROM messages WHERE account_id = ? AND folder='inbox' AND id NOT IN
                   (SELECT id FROM (SELECT id FROM messages WHERE account_id = ? AND folder='inbox'
                    ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit . ") t)")
        ->execute([$acc['id'], $acc['id']]);

    return 'ok';
}
