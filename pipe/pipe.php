#!/usr/local/bin/php -q
<?php
/**
 * Email pipe — wired to "Default Address" (catch-all) of metora.ir in cPanel.
 * Reads the raw message on STDIN, parses it, and delivers to the local
 * account whose username matches the recipient local part.
 */
if (PHP_SAPI !== 'cli') exit(1);

$home = '/home/YOURUSER/temp.example.com';
require_once $home . '/includes/config.php';
require_once $home . '/includes/db.php';

$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') exit(0);

// ── find recipient ───────────────────────────────────────
$toEmail = null;
if (preg_match('/^Delivered-To:\s*(\S+)/mi', $raw, $m)) $toEmail = strtolower($m[1]);
if (!$toEmail && preg_match('/^X-Original-To:\s*(\S+)/mi', $raw, $m)) $toEmail = strtolower($m[1]);
if (!$toEmail && preg_match('/^To:\s*((?:[^\r\n]|\r?\n\s)+)/mi', $raw, $m)) {
    $line = preg_replace('/\s+/', ' ', $m[1]);
    if (preg_match('/[\w.+-]+@[\w.-]+/u', $line, $mm)) $toEmail = strtolower($mm[0]);
}
if (!$toEmail || !str_ends_with($toEmail, '@' . MAIL_DOMAIN)) exit(0);  // not for us

$username = explode('@', $toEmail)[0];

$pdo = db();$stmt = $pdo->prepare("SELECT id, is_premium, role FROM accounts WHERE email = ? LIMIT 1");
$stmt->execute([$toEmail]);
$acc = $stmt->fetch();
if (!$acc) exit(0);  // unknown recipient → drop silently

// ── parse headers/body ───────────────────────────────────
$split = strpos($raw, "\r\n\r\n");
$sepLen = 4;
if ($split === false) { $split = strpos($raw, "\n\n"); $sepLen = 2; }
$headersRaw = substr($raw, 0, $split);
$bodyRaw = substr($raw, $split + $sepLen);

// unfold headers
$headersRaw = preg_replace('/\r?\n[ \t]+/', ' ', $headersRaw);
$hdr = [];
foreach (explode("\n", str_replace("\r\n", "\n", $headersRaw)) as $line) {
    $c = strpos($line, ':');
    if ($c !== false) $hdr[strtolower(substr($line, 0, $c))] = trim(substr($line, $c + 1));
}

$subject = $hdr['subject'] ?? '(no subject)';
$from = $hdr['from'] ?? 'unknown@unknown';
$toHeader = $hdr['to'] ?? $toEmail;

function decode_hdr($s) {
    $s = imap_mime_header_decode($s);
    $out = '';
    foreach ((array)$s as $p) $out .= $p->text;
    return $out;
}
$subject = decode_hdr($subject);
$from = decode_hdr($from);
$toHeader = decode_hdr($toHeader);

// detect content type & boundary
$ctype = $hdr['content-type'] ?? 'text/plain';
$boundary = null;
if (preg_match('/boundary="?([^";]+)"?/i', $ctype, $m)) $boundary = $m[1];

$textBody = null; $htmlBody = null; $attachments = [];

function decode_part($content, $encoding) {
    switch (strtolower($encoding ?? '')) {
        case 'base64': return base64_decode($content);
        case 'quoted-printable': return quoted_printable_decode($content);
        default: return $content;
    }
}

if ($boundary) {
    $parts = explode('--' . $boundary, $bodyRaw);
    array_shift($parts);  // preamble
    foreach ($parts as $part) {
        if (trim($part) === '--') break;
        $ps = strpos($part, "\r\n\r\n"); $pl = 4;
        if ($ps === false) { $ps = strpos($part, "\n\n"); $pl = 2; }
        if ($ps === false) continue;
        $ph = substr($part, 0, $ps);
        $pc = substr($part, $ps + $pl);
        $ph = preg_replace('/\r?\n[ \t]+/', ' ', $ph);
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
            $textBody = decode_part($pc, $penc);
        } elseif (!$isAtt && stripos($pct, 'text/html') === 0 && $htmlBody === null) {
            $htmlBody = decode_part($pc, $penc);
        } else {
            // attachment
            $fname = 'file';
            if (preg_match('/filename="?([^";]+)"?/i', $dispo . ' ' . $pct, $m)) $fname = decode_hdr($m[1]);
            $data = decode_part($pc, $penc);
            if (strlen($data) > MAX_UPLOAD_BYTES) continue;  // reject oversize
            $dir = STORAGE_DIR . '/att';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $rel = 'att/' . bin2hex(random_bytes(12)) . '-' . preg_replace('/[^\w.\- ]/u', '_', $fname);
            file_put_contents(STORAGE_DIR . '/' . $rel, $data);
            $attachments[] = [$fname, $rel, strlen($data)];
        }
    }
} else {
    $enc = $hdr['content-transfer-encoding'] ?? '';
    if (stripos($ctype, 'text/html') === 0) $htmlBody = decode_part($bodyRaw, $enc);
    else $textBody = decode_part($bodyRaw, $enc);
}

if ($textBody === null && $htmlBody === null) $textBody = '';
$totalSize = strlen($raw);

// ── store ────────────────────────────────────────────────
$stmt = $pdo->prepare("INSERT INTO messages (account_id, folder, from_addr, to_addr, subject, body_text, body_html, size_bytes)
                       VALUES (?, 'inbox', ?, ?, ?, ?, ?, ?)");
$stmt->execute([$acc['id'], $from, $toHeader, mb_substr($subject, 0, 500), $textBody, $htmlBody, $totalSize]);
$mid = (int)$pdo->lastInsertId();
foreach ($attachments as $a) {
    $pdo->prepare("INSERT INTO attachments (message_id, filename, mime, size_bytes, file_path) VALUES (?,?,?,?,?)")
        ->execute([$mid, $a[0], 'application/octet-stream', $a[2], $a[1]]);
}

// enforce inbox limit: delete oldest beyond limit
$limit = ($acc['role'] === 'admin') ? INBOX_LIMIT_PREMIUM
       : (($acc['is_premium'] ?? 0) ? INBOX_LIMIT_PREMIUM : INBOX_LIMIT_FREE);
$pdo->exec("DELETE FROM messages WHERE account_id=" . (int)$acc['id'] . " AND folder='inbox' AND id NOT IN
            (SELECT id FROM (SELECT id FROM messages WHERE account_id=" . (int)$acc['id'] . " AND folder='inbox'
             ORDER BY created_at DESC LIMIT $limit) t)");

exit(0);
