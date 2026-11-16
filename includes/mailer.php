<?php
/**
 * Outgoing mail: builds a MIME message (with attachments) and sends it
 * through the local MTA via PHP mail(). Called from web context.
 */
require_once __DIR__ . '/helpers.php';

function build_mime(array $mail, array $attachments = []): string {
    $from = $mail['from'];                       // e.g. alice@metora.ir
    $to = $mail['to'];
    $subject = mb_encode_mimeheader($mail['subject'] ?: '(no subject)', 'UTF-8', 'B', "\r\n");
    $text = $mail['body'] ?? '';

    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'X-Mailer: Metora-TempMail',
        'Date: ' . date('r'),
    ];

    if (empty($attachments)) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text));
    } else {
        $boundary = '=_tm' . bin2hex(random_bytes(10));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        $parts = [];
        $parts[] = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($text));
        foreach ($attachments as $a) {
            // $a = [filename, storage-rel-path]
            $data = file_get_contents(STORAGE_DIR . '/' . $a[1]);
            if ($data === false) continue;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($data) ?: 'application/octet-stream';
            $fname = mb_encode_mimeheader($a[0], 'UTF-8', 'B');
            $parts[] = "--$boundary\r\nContent-Type: $mime; name=\"$fname\"\r\n"
                     . "Content-Transfer-Encoding: base64\r\n"
                     . "Content-Disposition: attachment; filename=\"$fname\"\r\n\r\n"
                     . chunk_split(base64_encode($data));
        }
        $parts[] = "--$boundary--";
        $body = implode("\r\n", $parts);
    }

    return mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

/** persist the sent copy into the sender's Sent folder */
function save_sent_copy(int $account_id, array $mail, array $attachments = []): int {
    $pdo = db();
    $total = strlen($mail['body'] ?? '');
    foreach ($attachments as $a) $total += @filesize(STORAGE_DIR . '/' . $a[1]) ?: 0;
    $stmt = $pdo->prepare("INSERT INTO messages (account_id, folder, from_addr, to_addr, subject, body_text, size_bytes)
                           VALUES (?, 'sent', ?, ?, ?, ?, ?)");
    $stmt->execute([$account_id, $mail['from'], $mail['to'], $mail['subject'], $mail['body'], $total]);
    $mid = (int)$pdo->lastInsertId();
    foreach ($attachments as $a) {
        $pdo->prepare("INSERT INTO attachments (message_id, filename, mime, size_bytes, file_path) VALUES (?,?,?,?,?)")
            ->execute([$mid, $a[0], 'application/octet-stream', (int)(@filesize(STORAGE_DIR . '/' . $a[1]) ?: 0), $a[1]]);
    }
    return $mid;
}
