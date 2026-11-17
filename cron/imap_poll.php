#!/usr/local/bin/php -q
<?php
/**
 * Cron: imap_poll — runs every minute.
 *
 * Delivery pipeline (robust):
 *   1. try several IMAP endpoints with graceful fallback:
 *      127.0.0.1:143/notls → localhost:143 → mail.domain:143/notls →
 *      127.0.0.1:993 (TLS) → mail.domain:993
 *   2. every message is parsed by the SHARED parser (includes/mail_parser.php)
 *   3. daemon/bounce mails are dropped, real mails delivered to the account
 *   4. processed messages are always deleted (expunge) so the mailbox
 *      never fills up
 *   5. state/imap_errors.txt keeps only the LAST 200 lines (no infinite growth)
 *
 * Falls back to a raw-socket IMAP client when the PHP IMAP extension is absent.
 */

$home = __DIR__ . '/..';
require_once $home . '/includes/config.php';
require_once $home . '/includes/db.php';
require_once $home . '/includes/mail_parser.php';

$stateDir = STATE_DIR;
if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);

function poll_log_error(string $msg): void {
    $f = STATE_DIR . '/imap_errors.txt';
    $lines = file_exists($f) ? file($f) : [];
    $lines[] = date('c') . ' ' . $msg . "\n";
    file_put_contents($f, implode('', array_slice($lines, -200)));
}

$processed = 0; $droppedDaemon = 0; $unknown = 0;

/* ── strategy A: PHP IMAP extension ── */
$usedImap = false;
if (function_exists('imap_open')) {
    $hosts = [
        '{127.0.0.1:143/notls}INBOX',
        '{localhost:143/notls}INBOX',
        '{mail.' . MAIL_DOMAIN . ':143/notls}INBOX',
        '{127.0.0.1:993/imap/ssl/novalidate-cert}INBOX',
        '{mail.' . MAIL_DOMAIN . ':993/imap/ssl/novalidate-cert}INBOX',
        '{127.0.0.1:143/novalidate-cert}INBOX',
    ];
    $mbox = false;
    foreach ($hosts as $host) {
        $mbox = @imap_open($host, CATCHALL_USER, CATCHALL_PASS, 0, 1);
        if ($mbox) break;
        imap_errors(); imap_alerts();   // clear buffers
    }
    if ($mbox) {
        $usedImap = true;
        $check = imap_check($mbox);
        if ($check && $check->Nmsgs > 0) {
            $overview = imap_fetch_overview($mbox, "1:{$check->Nmsgs}", 0);
            foreach (array_reverse((array)$overview) as $msg) {
                $rawHead = imap_fetchheader($mbox, $msg->msgno, FT_PREFETCHTEXT);
                $raw     = $rawHead . "\n" . imap_body($mbox, $msg->msgno);

                // envelope discovery (original recipient before catch-all rewrote it)
                $toEmail = null;
                foreach (['Envelope-To', 'X-Original-To', 'Delivered-To', 'X-Envelope-To'] as $hk) {
                    if (preg_match('/^' . $hk . ':\s*(\S+)/mi', $rawHead, $m)) {
                        $cand = strtolower(trim($m[1]));
                        if ($cand && $cand !== strtolower(CATCHALL_USER)) { $toEmail = $cand; break; }
                    }
                }
                if (!$toEmail && !empty($msg->to)) {
                    if (preg_match('/[\w.+-]+@[\w.-]+/', $msg->to, $m)) $toEmail = strtolower($m[0]);
                }

                if ($toEmail && str_ends_with($toEmail, '@' . MAIL_DOMAIN)) {
                    $parsed = mp_parse_mail($raw);
                    $r = mp_deliver($parsed, $toEmail, $raw);
                    if ($r === 'ok') $processed++;
                    elseif ($r === 'daemon') $droppedDaemon++;
                    else $unknown++;
                }
                @imap_delete($mbox, $msg->msgno);   // always consume (unknown recipient too)
            }
            @imap_expunge($mbox);
        }
        imap_close($mbox);
    } else {
        poll_log_error('imap connect failed: ' . (imap_last_error() ?: 'unknown'));
    }
}

/* ── strategy B: raw-socket IMAP client (no extension needed) ── */
if (!$usedImap) {
    require_once __DIR__ . '/imap_socket.php';
    $imap = new ImapSocketClient();
    $endpoints = [
        ['127.0.0.1', 143, false],
        ['localhost', 143, false],
        ['mail.' . MAIL_DOMAIN, 143, false],
        ['127.0.0.1', 993, true],
    ];
    $connected = false;
    foreach ($endpoints as $ep) {
        if ($imap->connect($ep[0], $ep[1], $ep[2], CATCHALL_USER, CATCHALL_PASS)) { $connected = true; break; }
    }
    if ($connected) {
        $uids = $imap->searchAll();
        foreach ($uids as $uid) {
            $raw = $imap->fetchRaw($uid);
            if ($raw === null) continue;
            $rawHead = substr($raw, 0, strpos($raw, "\r\n\r\n") ?: 4096);
            $toEmail = null;
            foreach (['Envelope-To', 'X-Original-To', 'Delivered-To', 'X-Envelope-To'] as $hk) {
                if (preg_match('/^' . $hk . ':\s*(\S+)/mi', $rawHead, $m)) {
                    $cand = strtolower(trim($m[1]));
                    if ($cand && $cand !== strtolower(CATCHALL_USER)) { $toEmail = $cand; break; }
                }
            }
            if (!$toEmail && preg_match('/^To:\s*((?:[^\r\n]|\r?\n\s)+)/mi', $rawHead, $m)) {
                $line = preg_replace('/\s+/', ' ', $m[1]);
                if (preg_match('/[\w.+-]+@[\w.-]+/u', $line, $mm)) $toEmail = strtolower($mm[0]);
            }
            if ($toEmail && str_ends_with($toEmail, '@' . MAIL_DOMAIN)) {
                $parsed = mp_parse_mail($raw);
                $r = mp_deliver($parsed, $toEmail, $raw);
                if ($r === 'ok') $processed++;
                elseif ($r === 'daemon') $droppedDaemon++;
                else $unknown++;
            }
            $imap->deleteMessage($uid);
        }
        $imap->expunge();
        $imap->disconnect();
    } else {
        poll_log_error('socket connect failed: ' . $imap->lastError);
    }
}

file_put_contents($stateDir . '/last_poll.txt',
    date('c') . " processed=$processed daemon_dropped=$droppedDaemon unknown=$unknown\n", FILE_APPEND);
exit(0);
