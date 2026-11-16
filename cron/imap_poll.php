#!/usr/local/bin/php -q
<?php
/**
 * Cron: imap_poll — runs every minute.
 * Connects to the catch-all mailbox (tempmail@metora.ir) via IMAP,
 * moves each new message into the recipient's app inbox (DB), then
 * deletes it from the mailbox. Recipient = the account whose
 * username@metora.ir matches the Delivered-To envelope.
 */
$home = '/home/YOURUSER/temp.example.com';
require_once $home . '/includes/config.php';
require_once $home . '/includes/db.php';

$stateDir = STATE_DIR;
if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);

if (!function_exists('imap_open')) { exit(0); }

$hosts = [
    '{127.0.0.1:143/notls}INBOX',
    '{127.0.0.1:993/imap/ssl/novalidate-cert}INBOX',
    '{mail.metora.ir:143/notls}INBOX',
    '{mail.metora.ir:993/imap/ssl/novalidate-cert}INBOX',
];
$mbox = false;
$usedHost = '';
foreach ($hosts as $host) {
    $mbox = @imap_open($host, CATCHALL_USER, CATCHALL_PASS);
    if ($mbox) { $usedHost = $host; break; }
    imap_errors(); // clear error buffer
}
if (!$mbox) {
    file_put_contents($stateDir . '/imap_errors.txt', date('c') . ' connect failed: ' . imap_last_error() . "\n", FILE_APPEND);
    exit(0);
}

$pdo = db();
$processed = 0;
$check = imap_check($mbox);
if ($check && $check->Nmsgs > 0) {
    $overview = imap_fetch_overview($mbox, "1:{$check->Nmsgs}", 0);
    foreach (array_reverse($overview) as $msg) {
        $uid = (int)$msg->uid;
        // raw headers for envelope discovery
        $rawHead = imap_fetchheader($mbox, $msg->msgno, FT_PREFETCHTEXT);
        $toEmail = null;
        // Envelope-To / X-Original-To carry the ORIGINAL recipient
        // (Delivered-To is rewritten to the catch-all address by the MTA).
        if (preg_match('/^Envelope-To:\s*(\S+)/mi', $rawHead, $m)) $toEmail = strtolower($m[1]);
        if (!$toEmail && preg_match('/^X-Original-To:\s*(\S+)/mi', $rawHead, $m)) $toEmail = strtolower($m[1]);
        if (!$toEmail && preg_match('/^Delivered-To:\s*(\S+)/mi', $rawHead, $m)) $toEmail = strtolower($m[1]);
        // reject catch-all's own address — it means the envelope got lost
        if ($toEmail === strtolower(CATCHALL_USER)) $toEmail = null;
        if (!$toEmail && !empty($msg->to)) {
            if (preg_match('/[\w.+-]+@[\w.-]+/', $msg->to, $m)) $toEmail = strtolower($m[0]);
        }
        if (!$toEmail || !str_ends_with($toEmail, '@' . MAIL_DOMAIN)) {
            @imap_delete($mbox, $msg->msgno);
            continue;
        }

        $stmt = $pdo->prepare("SELECT id, is_premium, role FROM accounts WHERE email = ? LIMIT 1");
        $stmt->execute([$toEmail]);
        $acc = $stmt->fetch();
        if (!$acc) { @imap_delete($mbox, $msg->msgno); continue; }  // unknown recipient

        $raw = imap_fetchheader($mbox, $msg->msgno) . "\n" . imap_body($mbox, $msg->msgno);

        // ── parse: reuse the same logic as pipe (headers/body/parts) ──
        $split = strpos($raw, "\r\n\r\n"); $sepLen = 4;
        if ($split === false) { $split = strpos($raw, "\n\n"); $sepLen = 2; }
        $headersRaw = preg_replace('/\r?\n[ \t]+/', ' ', substr($raw, 0, $split));
        $bodyRaw = substr($raw, $split + $sepLen);
        $hdr = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $headersRaw)) as $line) {
            $c = strpos($line, ':');
            if ($c !== false) $hdr[strtolower(substr($line, 0, $c))] = trim(substr($line, $c + 1));
        }
        $subject = imap_mime_header_decode($hdr['subject'] ?? '(no subject)');
        $subject = implode('', array_map(fn($p) => $p->text, (array)$subject));
        $from = $hdr['from'] ?? 'unknown@unknown';
        $fd = imap_mime_header_decode($from); $from = implode('', array_map(fn($p) => $p->text, (array)$fd));
        $toHeader = $hdr['to'] ?? $toEmail;

        $ctype = $hdr['content-type'] ?? 'text/plain';
        $boundary = null;
        if (preg_match('/boundary="?([^";]+)"?/i', $ctype, $m)) $boundary = $m[1];

        $textBody = null; $htmlBody = null; $attachments = [];
        $dec = function ($content, $encoding) {
            switch (strtolower($encoding ?? '')) {
                case 'base64': return base64_decode($content);
                case 'quoted-printable': return quoted_printable_decode($content);
                default: return $content;
            }
        };

        if ($boundary) {
            $parts = explode('--' . $boundary, $bodyRaw);
            array_shift($parts);
            foreach ($parts as $part) {
                if (trim($part) === '--') break;
                $ps = strpos($part, "\r\n\r\n"); $pl = 4;
                if ($ps === false) { $ps = strpos($part, "\n\n"); $pl = 2; }
                if ($ps === false) continue;
                $ph = preg_replace('/\r?\n[ \t]+/', ' ', substr($part, 0, $ps));
                $pc = substr($part, $ps + $pl);
                $partHdr = [];
                foreach (explode("\n", str_replace("\r\n", "\n", $ph)) as $line) {
                    $c = strpos($line, ':');
                    if ($c !== false) $partHdr[strtolower(substr($line, 0, $c))] = trim(substr($line, $c + 1));
                }
                $pct = $partHdr['content-type'] ?? 'text/plain';
                $penc = $partHdr['content-transfer-encoding'] ?? '';
                $dispo = $partHdr['content-disposition'] ?? '';
                $isAtt = stripos($dispo, 'attachment') !== false || stripos($pct, 'name=') !== false;
                if (!$isAtt && stripos($pct, 'text/plain') === 0 && $textBody === null) {
                    $textBody = $dec($pc, $penc);
                } elseif (!$isAtt && stripos($pct, 'text/html') === 0 && $htmlBody === null) {
                    $htmlBody = $dec($pc, $penc);
                } else {
                    $fname = 'file';
                    if (preg_match('/filename="?([^";]+)"?/i', $dispo . ' ' . $pct, $m2)) {
                        $fd2 = imap_mime_header_decode($m2[1]);
                        $fname = implode('', array_map(fn($p) => $p->text, (array)$fd2));
                    }
                    $data = $dec($pc, $penc);
                    if (strlen($data) > MAX_UPLOAD_BYTES) { continue; }
                    $dir = STORAGE_DIR . '/att';
                    if (!is_dir($dir)) @mkdir($dir, 0750, true);
                    $rel = 'att/' . bin2hex(random_bytes(12)) . '-' . preg_replace('/[^\w.\- ]/u', '_', $fname);
                    file_put_contents(STORAGE_DIR . '/' . $rel, $data);
                    $attachments[] = [$fname, $rel, strlen($data)];
                }
            }
        } else {
            $enc = $hdr['content-transfer-encoding'] ?? '';
            if (stripos($ctype, 'text/html') === 0) $htmlBody = $dec($bodyRaw, $enc);
            else $textBody = $dec($bodyRaw, $enc);
        }
        if ($textBody === null && $htmlBody === null) $textBody = '';

        // dedup: identical subject/from within same minute → skip
        $stmt = $pdo->prepare("INSERT INTO messages (account_id, folder, from_addr, to_addr, subject, body_text, body_html, size_bytes)
                               VALUES (?, 'inbox', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$acc['id'], $from, $toHeader, mb_substr($subject, 0, 500), $textBody, $htmlBody, strlen($raw)]);
        $mid = (int)$pdo->lastInsertId();
        foreach ($attachments as $a) {
            $pdo->prepare("INSERT INTO attachments (message_id, filename, mime, size_bytes, file_path) VALUES (?,?,?,?,?)")
                ->execute([$mid, $a[0], 'application/octet-stream', $a[2], $a[1]]);
        }

        // enforce inbox limit
        $limit = ($acc['role'] === 'admin') ? INBOX_LIMIT_PREMIUM
               : ($acc['is_premium'] ? INBOX_LIMIT_PREMIUM : INBOX_LIMIT_FREE);
        $pdo->exec("DELETE FROM messages WHERE account_id=" . (int)$acc['id'] . " AND folder='inbox' AND id NOT IN
                    (SELECT id FROM (SELECT id FROM messages WHERE account_id=" . (int)$acc['id'] . " AND folder='inbox'
                     ORDER BY created_at DESC LIMIT $limit) t)");

        @imap_delete($mbox, $msg->msgno);
        $processed++;
    }
    @imap_expunge($mbox);
}
imap_close($mbox);

file_put_contents($stateDir . '/last_poll.txt', date('c') . " processed=$processed\n", FILE_APPEND);
exit(0);
