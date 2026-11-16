#!/usr/local/bin/php -q
<?php
/**
 * Cron: process_scheduled — every minute.
 * Sends due scheduled messages, drops expired ones (>5 pending), TTL-purges sent history.
 * Uses GET_LOCK so overlapping runs are safe.
 */
$home = '/home3/qvjdxmzt/temp.metora.ir';
require_once $home . '/includes/config.php';
require_once $home . '/includes/helpers_standalone.php';

$pdo = cli_db();
$stateDir = STATE_DIR;
if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);

// serialize concurrent runs
$lock = $pdo->query("SELECT GET_LOCK('tm_sched', 0) AS l")->fetch();
if (!$lock || (int)$lock['l'] !== 1) exit(0); // another run is active

$log = fn($m) => file_put_contents($stateDir . '/last_scheduled.txt', date('c') . " $m\n", FILE_APPEND);

// ── 1. drop users exceeding 5 pending (rule enforcement, oldest first)
$over = $pdo->query("SELECT account_id, COUNT(*) c FROM scheduled_messages
                     WHERE status='pending' GROUP BY account_id HAVING c > 5")->fetchAll();
foreach ($over as $row) {
    $stmt = $pdo->prepare("SELECT id, att_json FROM scheduled_messages
                           WHERE account_id=? AND status='pending' ORDER BY send_at ASC LIMIT " . ((int)$row['c'] - 5));
    $stmt->execute([$row['account_id']]);
    foreach ($stmt->fetchAll() as $r) {
        foreach (json_decode($r['att_json'] ?? '[]', true) ?: [] as $a) @unlink(STORAGE_DIR . '/' . $a['path']);
        $pdo->prepare("DELETE FROM scheduled_messages WHERE id=?")->execute([$r['id']]);
        $log("dropped excess #{$r['id']} acc={$row['account_id']}");
    }
}

// ── 2. send due messages (send_at stored as Tehran wall-clock)
$tehran = (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:59');
$stmt = $pdo->prepare("SELECT s.*, a.email from_email, a.id acc_id FROM scheduled_messages s
                       JOIN accounts a ON a.id = s.account_id
                       WHERE s.status='pending' AND s.send_at <= ?");
$stmt->execute([$tehran]);
$due = $stmt->fetchAll();

$sentN = $failN = 0;
foreach ($due as $m) {
    $atts = json_decode($m['att_json'] ?? '[]', true) ?: [];

    $boundary = '=_tm' . bin2hex(random_bytes(10));
    $headers = [
        'From: ' . $m['from_email'],
        'Reply-To: ' . $m['from_email'],
        'MIME-Version: 1.0',
        'X-Mailer: Metora-TempMail',
        'Date: ' . date('r'),
    ];
    $subject = mb_encode_mimeheader($m['subject'] ?: '(no subject)', 'UTF-8', 'B', "\r\n");

    if (!$atts) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($m['body_text'] ?? ''));
    } else {
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        $parts = ["--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($m['body_text'] ?? ''))];
        foreach ($atts as $a) {
            $data = @file_get_contents(STORAGE_DIR . '/' . $a['path']);
            if ($data === false) continue;
            $fname = mb_encode_mimeheader($a['name'], 'UTF-8', 'B');
            $parts[] = "--$boundary\r\nContent-Type: {$a['mime']}; name=\"$fname\"\r\n"
                     . "Content-Transfer-Encoding: base64\r\n"
                     . "Content-Disposition: attachment; filename=\"$fname\"\r\n\r\n"
                     . chunk_split(base64_encode($data));
        }
        $parts[] = "--$boundary--";
        $body = implode("\r\n", $parts);
    }

    $ok = @mail($m['to_addr'], $subject, $body, implode("\r\n", $headers), '-f' . $m['from_email']);

    if ($ok) {
        // store a copy in the sender's Sent folder
        $total = strlen($m['body_text'] ?? '');
        foreach ($atts as $a) $total += (int)($a['size'] ?? 0);
        $pdo->prepare("INSERT INTO messages (account_id, folder, from_addr, to_addr, subject, body_text, size_bytes, created_at)
                       VALUES (?, 'sent', ?, ?, ?, ?, ?, ?)")
            ->execute([$m['acc_id'], $m['from_email'], $m['to_addr'], $m['subject'], $m['body_text'], $total, $m['send_at']]);
        $mid = (int)$pdo->lastInsertId();
        foreach ($atts as $a) {
            $pdo->prepare("INSERT INTO attachments (message_id, filename, mime, size_bytes, file_path) VALUES (?,?,?,?,?)")
                ->execute([$mid, $a['name'], $a['mime'], (int)($a['size'] ?? 0), $a['path']]);
        }
        // copy file from sched/ to att/ so it lives/dies with the message
        foreach ($atts as $a) {
            @rename(STORAGE_DIR . '/' . $a['path'], STORAGE_DIR . '/att/' . basename($a['path']));
        }
        $pdo->prepare("UPDATE scheduled_messages SET status='sent', sent_at=NOW() WHERE id=?")->execute([$m['id']]);
        $sentN++;
        $log("sent #{$m['id']} → {$m['to_addr']}");
    } else {
        $pdo->prepare("UPDATE scheduled_messages SET status='failed', sent_at=NOW() WHERE id=?")->execute([$m['id']]);
        foreach ($atts as $a) @unlink(STORAGE_DIR . '/' . $a['path']);
        $failN++;
        $log("FAILED #{$m['id']} → {$m['to_addr']}");
    }
}

// ── 3. TTL: keep sent/failed/canceled history only 7 days
$pdo->exec("DELETE FROM scheduled_messages
            WHERE status IN ('sent','failed','canceled') AND sent_at < (NOW() - INTERVAL 7 DAY)");

$pdo->query("SELECT RELEASE_LOCK('tm_sched')");
echo "scheduled: sent=$sentN failed=$failN\n";
exit(0);
