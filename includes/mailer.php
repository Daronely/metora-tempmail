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
/* ═══════════════════════════════════════════════════════════════
   Direct-SMTP delivery engine (v6.2)
   Why: on this shared host the local exim queue stalls for app mail
   (accepted by mail() but never relayed), while a direct socket to
   the recipient MX is accepted fine (verified: Gmail answers
   250 2.0.0 OK). We therefore deliver directly to the recipient's
   MX with STARTTLS when offered, and only fall back to mail().
   Strategy: direct MX → mail() fallback.
   ═══════════════════════════════════════════════════════════════ */

function smtp_mx_hosts(string $domain): array {
    $hosts = [];
    if (function_exists('getmxrr')) {
        $mx = []; $w = [];
        if (getmxrr($domain, $mx, $w)) {
            array_multisort($w, $mx);
            $hosts = $mx;
        }
    }
    if (!$hosts && function_exists('dns_get_record')) {
        foreach ((dns_get_record($domain, DNS_MX) ?: []) as $r) {
            if (!empty($r['target'])) $hosts[] = $r['target'];
        }
    }
    // local-domain mail must not leave the machine
    if (strtolower($domain) === strtolower(MAIL_DOMAIN)) return ['LOCAL'];
    if (!$hosts) $hosts = [$domain]; // implicit MX (A record)
    return array_slice($hosts, 0, 3);
}

/**
 * Plaintext session (TLS unavailable on this host but the MX still accepts mail).
 */
function smtp_try_host_plaintext(string $host, array $mail, string $headers, string $body): array {
    $fp = @fsockopen($host, 25, $errno, $errstr, 12);
    if (!$fp) return ['ok'=>false, 'error'=>"اتصال به $host برقرار نشد", 'detail'=>"$errno $errstr"];
    stream_set_timeout($fp, 20);
    $read = function() use ($fp): string {
        $d = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $d .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        return trim($d);
    };
    $cmd = function(string $c, array $expect) use ($fp, $read): array {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        $code = (int)substr($r, 0, 3);
        return [in_array($code, $expect, true), $code, $r];
    };
    $fail = function(string $stage, string $resp) use ($fp): array {
        @fwrite($fp, "QUIT\r\n"); @fclose($fp);
        return ['ok'=>false, 'error'=>"سرور گیرنده در مرحله $stage پیام را رد کرد", 'detail'=>$resp];
    };
    $banner = $read();
    if ((int)substr($banner, 0, 3) !== 220) return $fail('banner', $banner);
    [$ok, , $r] = $cmd('EHLO ' . MAIL_DOMAIN, [250]);
    if (!$ok) return $fail('EHLO', $r);
    [$ok, , $r] = $cmd('MAIL FROM:<' . $mail['from'] . '>', [250]);
    if (!$ok) return $fail('MAIL FROM', $r);
    [$ok, , $r] = $cmd('RCPT TO:<' . $mail['to'] . '>', [250, 251]);
    if (!$ok) return $fail('RCPT TO', $r);
    [$ok, , $r] = $cmd('DATA', [354]);
    if (!$ok) return $fail('DATA', $r);
    $data = preg_replace('/^\./m', '..', $headers . "\r\n\r\n" . $body);
    foreach (str_split($data, 8192) as $chunk) fwrite($fp, $chunk);
    fwrite($fp, "\r\n.\r\n");
    $r = $read();
    $code = (int)substr($r, 0, 3);
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    if ($code !== 250) return ['ok'=>false, 'error'=>'سرور گیرنده پیام را نپذیرفت', 'detail'=>$r];
    return ['ok'=>true, 'detail'=>$r];
}

/**
 * One SMTP session against $host. Returns ['ok'=>bool,'error'=>?string,'detail'=>?string].
 * $headers/$body come from build_mime(); To/From envelope from $mail.
 */
function smtp_try_host(string $host, array $mail, string $headers, string $body): array {
    $fp = @fsockopen($host, 25, $errno, $errstr, 12);
    if (!$fp) return ['ok'=>false, 'error'=>"اتصال به $host برقرار نشد", 'detail'=>"$errno $errstr"];
    stream_set_timeout($fp, 20);

    $read = function() use ($fp): string {
        $d = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $d .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        return trim($d);
    };
    $cmd = function(string $c, array $expect) use ($fp, $read): array {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        $code = (int)substr($r, 0, 3);
        return [in_array($code, $expect, true), $code, $r];
    };
    $fail = function(string $stage, string $resp) use ($fp): array {
        @fwrite($fp, "QUIT\r\n"); @fclose($fp);
        return ['ok'=>false, 'error'=>"سرور گیرنده در مرحله $stage پیام را رد کرد", 'detail'=>$resp];
    };

    $banner = $read();
    if ((int)substr($banner, 0, 3) !== 220) return $fail('banner', $banner);

    [$ok, , $r] = $cmd('EHLO ' . MAIL_DOMAIN, [250]);
    if (!$ok) return $fail('EHLO', $r);

    // opportunistic STARTTLS (better reception at Gmail/Outlook).
    // If the TLS handshake fails on this host (some shared hosts block it),
    // restart the session in plaintext instead of giving up.
    if (stripos($r, 'STARTTLS') !== false && function_exists('stream_socket_enable_crypto')) {
        [$okT, , $rT] = $cmd('STARTTLS', [220]);
        if ($okT) {
            $crypto_ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto_ok) {
                [$ok2, , $r2] = $cmd('EHLO ' . MAIL_DOMAIN, [250]);
                if (!$ok2) return $fail('EHLO-TLS', $r2);
            } else {
                @fwrite($fp, "QUIT\r\n"); @fclose($fp);
                return smtp_try_host_plaintext($host, $mail, $headers, $body);
            }
        }
    }

    [$ok, , $r] = $cmd('MAIL FROM:<' . $mail['from'] . '>', [250]);
    if (!$ok) return $fail('MAIL FROM', $r);
    [$ok, , $r] = $cmd('RCPT TO:<' . $mail['to'] . '>', [250, 251]);
    if (!$ok) return $fail('RCPT TO', $r);
    [$ok, , $r] = $cmd('DATA', [354]);
    if (!$ok) return $fail('DATA', $r);

    // dot-stuffing per RFC 5321
    $data = preg_replace('/^\./m', '..', $headers . "\r\n\r\n" . $body);
    // write in chunks so big attachments don't blow memory limits on the socket write
    foreach (str_split($data, 8192) as $chunk) fwrite($fp, $chunk);
    fwrite($fp, "\r\n.\r\n");

    $r = $read();
    $code = (int)substr($r, 0, 3);
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    if ($code !== 250) {
        return ['ok'=>false, 'error'=>'سرور گیرنده پیام را نپذیرفت', 'detail'=>$r];
    }
    return ['ok'=>true, 'detail'=>$r];
}

function send_mail(array $mail, array $attachments = []): array {
    try {
        [$headers, $body] = build_mime($mail, $attachments);
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>'ساخت پیام ناموفق: ' . $e->getMessage()];
    }

    /* ── route 1: direct MX delivery (primary) ── */
    $domain = substr(strrchr($mail['to'], '@'), 1);
    if ($domain && function_exists('fsockopen')) {
        foreach (smtp_mx_hosts($domain) as $host) {
            if ($host === 'LOCAL') continue; // same-domain mail is routed via mail() below
            $res = smtp_try_host($host, $mail, $headers, $body);
            if ($res['ok']) return $res;
            $lastErr = $res;
        }
        // local-domain recipient → deliver through the usual MTA path (catch-all pipeline)
        if (isset($lastErr) === false || smtp_mx_hosts($domain) === ['LOCAL']) {
            // fall through to mail()
        } else {
            // every remote MX refused — still try the local MTA before giving up
        }
    }

    /* ── route 2: local MTA via mail() (fallback + same-domain loop) ── */
    if (!function_exists('mail')) return ['ok'=>false, 'error'=>'mail() غیرفعال است روی این سرور'];
    $subjectEnc = mb_encode_mimeheader($mail['subject'] ?: '(no subject)', 'UTF-8', 'B', "\r\n ");
    // pass the 5th arg so the MTA sets a proper envelope sender (SPF/Return-Path)
    $extra = function_exists('ini_get') && @ini_get('safe_mode') ? '' : '-f' . $mail['from'];
    $ok = @mail($mail['to'], $subjectEnc, $body, $headers, $extra);
    if (!$ok) {
        $e = error_get_last();
        return ['ok'=>false, 'error'=>'MTA ارسال را نپذیرفت', 'detail' => ($e['message'] ?? '') . (isset($lastErr) ? ' | ' . ($lastErr['detail'] ?? '') : '')];
    }
    return ['ok'=>true];
}
