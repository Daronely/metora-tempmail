<?php
/**
 * Export a message as a standard .eml file (RFC 822 / MIME).
 * Owner-only. Opens in Outlook/Thunderbird/Apple Mail.
 */
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare("SELECT * FROM messages WHERE id = ? AND account_id = ?");
$stmt->execute([$id, $u['id']]);
$m = $stmt->fetch();
if (!$m) json_error('یافت نشد', 404);

$atts = db()->prepare("SELECT * FROM attachments WHERE message_id = ?");
$atts->execute([$id]);
$atts = $atts->fetchAll();

$headers = [
    'From: ' . $m['from_addr'],
    'To: ' . $m['to_addr'],
    'Subject: ' . mb_encode_mimeheader($m['subject'] ?: '(no subject)', 'UTF-8', 'B', "\r\n"),
    'Date: ' . date('r', strtotime($m['created_at'])),
    'MIME-Version: 1.0',
    'X-Mailer: Metora-TempMail export',
];

$body = (string)($m['body_text'] ?? '');

if (!$atts) {
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';
    $eml = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
} else {
    $boundary = '=_tm' . bin2hex(random_bytes(10));
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
    $parts = [];
    $parts[] = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body));
    foreach ($atts as $a) {
        $data = @file_get_contents(STORAGE_DIR . '/' . $a['file_path']);
        if ($data === false) continue;
        $fname = mb_encode_mimeheader($a['filename'], 'UTF-8', 'B');
        $parts[] = "--$boundary\r\nContent-Type: {$a['mime']}; name=\"$fname\"\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "Content-Disposition: attachment; filename=\"$fname\"\r\n\r\n"
                 . chunk_split(base64_encode($data));
    }
    $parts[] = "--$boundary--";
    $eml = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
}

$fname = preg_replace('/[^\w.\-]/u', '_', 'mail-' . $id . '.eml');
header('Content-Type: message/rfc822; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($eml));
echo $eml;
