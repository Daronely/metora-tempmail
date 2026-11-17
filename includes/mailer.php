<?php
/**
 * Outgoing mail: builds a standards-compliant MIME message (text+html,
 * attachments, proper folding/encoding) and hands it to the local MTA
 * via PHP mail() with the envelope sender (-f) set for correct bounces.
 *
 * send_mail() returns an array (never throws) so the API can report a
 * precise error to the user instead of a generic failure.
 */
require_once __DIR__ . '/helpers.php';

function build_mime(array $mail, array $attachments = []): array {
    $from = $mail['from'];
    $to   = $mail['to'];
    $subject = $mail['subject'] ?: '(no subject)';
    $text = $mail['body'] ?? '';

    // derive a simple HTML twin so GUI clients render nicely
    $html = '<!DOCTYPE html><html><body dir="auto">'
          . '<div style="font-family:Vazirmatn,Tahoma,Arial,sans-serif;font-size:14px;line-height:1.8;color:#1a1a1a;white-space:pre-wrap">'
          . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
          . '</div></body></html>';

    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'To: ' . $to,
        'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n "),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . MAIL_DOMAIN . '>',
        'MIME-Version: 1.0',
        'X-Mailer: Metora-TempMail/1.0',
    ];

    if (empty($attachments)) {
        // multipart/alternative: text + html twin
        $altB = '=_alt' . bin2hex(random_bytes(8));
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$altB\"";
        $parts = [];
        $parts[] = "--$altB\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($text));
        $parts[] = "--$altB\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($html));
        $parts[] = "--$altB--";
        $body = implode("\r\n", $parts);
    } else {
        // mixed(alternative(text,html), attachments...)
        $mixB = '=_mix' . bin2hex(random_bytes(8));
        $altB = '=_alt' . bin2hex(random_bytes(8));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$mixB\"";
        $alt = [];
        $alt[] = "--$altB\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($text));
        $alt[] = "--$altB\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($html));
        $alt[] = "--$altB--";
        $parts = [];
        $parts[] = "--$mixB\r\nContent-Type: multipart/alternative; boundary=\"$altB\"\r\n\r\n"
                 . implode("\r\n", $alt);
        foreach ($attachments as $a) {
            $data = @file_get_contents(STORAGE_DIR . '/' . $a[1]);
            if ($data === false) continue;
            $mime = 'application/octet-stream';
            if (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fi->buffer($data) ?: $mime;
            }
            $fname = mb_encode_mimeheader($a[0], 'UTF-8', 'B');
            $parts[] = "--$mixB\r\nContent-Type: $mime; name=\"$fname\"\r\n"
                     . "Content-Transfer-Encoding: base64\r\n"
                     . "Content-Disposition: attachment; filename=\"$fname\"\r\n\r\n"
                     . chunk_split(base64_encode($data));
        }
        $parts[] = "--$mixB--";
        $body = implode("\r\n", $parts);
    }

    return [implode("\r\n", $headers), $body];
}

/** persist the sent copy into the sender's Sent folder */
function save_sent_copy(int $account_id, array $mail, array $attachments = []): int {
    $pdo = db();
    $total = strlen($mail['body'] ?? '');
    foreach ($attachments as $a) $total += (int)(@filesize(STORAGE_DIR . '/' . $a[1]) ?: 0);
    $stmt = $pdo->prepare("INSERT INTO messages (account_id, folder, from_addr, to_addr, subject, body_text, size_bytes)
                           VALUES (?, 'sent', ?, ?, ?, ?, ?)");
    $stmt->execute([$account_id, $mail['from'], $mail['to'], $mail['subject'] ?: '(no subject)', $mail['body'] ?? '', $total]);
    $mid = (int)$pdo->lastInsertId();
    foreach ($attachments as $a) {
        $pdo->prepare("INSERT INTO attachments (message_id, filename, mime, size_bytes, file_path) VALUES (?,?,?,?,?)")
            ->execute([$mid, $a[0], 'application/octet-stream', (int)(@filesize(STORAGE_DIR . '/' . $a[1]) ?: 0), $a[1]]);
    }
    return $mid;
}

/**
 * Build + send. Returns ['ok'=>bool, 'error'=>?string, 'detail'=>?string].
 * Bounces go to the sender's own temp address (they will be filtered as
 * daemon mail by the receiving pipeline, but Return-Path must be valid
 * for the MTA to accept the message at all).
 */
function send_mail(array $mail, array $attachments = []): array {
    if (!function_exists('mail')) return ['ok'=>false, 'error'=>'mail() غیرفعال است روی این سرور'];
    try {
        [$headers, $body] = build_mime($mail, $attachments);
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>'ساخت پیام ناموفق: ' . $e->getMessage()];
    }
    $subjectEnc = mb_encode_mimeheader($mail['subject'] ?: '(no subject)', 'UTF-8', 'B', "\r\n ");
    // pass the 5th arg so the MTA sets a proper envelope sender (SPF/Return-Path)
    $extra = function_exists('ini_get') && @ini_get('safe_mode') ? '' : '-f' . $mail['from'];
    $ok = @mail($mail['to'], $subjectEnc, $body, $headers, $extra);
    if (!$ok) {
        $e = error_get_last();
        return ['ok'=>false, 'error'=>'MTA ارسال را نپذیرفت', 'detail' => $e['message'] ?? ''];
    }
    return ['ok'=>true];
}
