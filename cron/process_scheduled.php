#!/usr/local/bin/php -q
<?php
/**
 * Cron: process_scheduled — every minute.
 * Sends due scheduled messages, drops expired ones (>5 pending), TTL-purges sent history.
 * Uses GET_LOCK so overlapping runs are safe.
 */
$home = dirname(__DIR__);   // works from any docroot layout
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
    // normalize attachment refs to [name, path] pairs for send_mail()
    $attPairs = [];
    foreach ($atts as $a) $attPairs[] = [$a['name'], $a['path']];

    $res = send_mail(
        ['from' => $m['from_email'], 'to' => $m['to_addr'], 'subject' => $m['subject'], 'body' => (string)($m['body_text'] ?? '')],
        $attPairs
    );
    $ok = $res['ok'];

    if ($ok) {
        // move file from sched/ to att/ FIRST so it lives/dies with the message,
        // and store the NEW path in the attachments row (old path caused broken downloads)
        foreach ($atts as $a) {
            $newRel = 'att/' . basename($a['path']);
            if (@rename(STORAGE_DIR . '/' . $a['path'], STORAGE_DIR . '/' . $newRel)) {
                $a['path'] = $newRel;
            }
        }
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
        $pdo->prepare("UPDATE scheduled_messages SET status='sent', sent_at=NOW() WHERE id=?")->execute([$m['id']]);
        $sentN++;
        $log("sent #{$m['id']} → {$m['to_addr']}");
    } else {
        $pdo->prepare("UPDATE scheduled_messages SET status='failed', sent_at=NOW() WHERE id=?")->execute([$m['id']]);
        foreach ($atts as $a) @unlink(STORAGE_DIR . '/' . $a['path']);
        $failN++;
        $log("FAILED #{$m['id']} → {$m['to_addr']} err={$res['error']}");
    }
}

// ── 3. TTL: keep sent/failed/canceled history only 7 days
$pdo->exec("DELETE FROM scheduled_messages
            WHERE status IN ('sent','failed','canceled') AND sent_at < (NOW() - INTERVAL 7 DAY)");

$pdo->query("SELECT RELEASE_LOCK('tm_sched')");
echo "scheduled: sent=$sentN failed=$failN\n";
exit(0);
