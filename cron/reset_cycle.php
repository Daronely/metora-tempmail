#!/usr/local/bin/php -q
<?php
/**
 * Cron: reset_cycle — every 6 hours.
 * Wipes all messages + attachments + send logs (a fresh "cycle"),
 * keeps accounts (email/password) intact.
 */
$home = '/home/YOURUSER/temp.example.com';
require_once $home . '/includes/config.php';
require_once $home . '/includes/helpers_standalone.php';

$pdo = cli_db();
$stateDir = STATE_DIR;
if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);

// attachment files on disk
$stmt = $pdo->query("SELECT file_path FROM attachments");
$n = 0;
foreach ($stmt->fetchAll() as $r) { @unlink(STORAGE_DIR . '/' . $r['file_path']); $n++; }

// leftover files not referenced (orphans after prior partial wipes)
if (is_dir(STORAGE_DIR . '/att')) {
    $keep = [];
    foreach ($pdo->query("SELECT file_path FROM attachments")->fetchAll() as $r) $keep[$r['file_path']] = 1;
    foreach (glob(STORAGE_DIR . '/att/*') as $f) {
        if (!isset($keep['att/' . basename($f)])) @unlink($f);
    }
}

$pdo->exec("DELETE FROM attachments");
$pdo->exec("DELETE FROM messages");
$pdo->exec("DELETE FROM send_log");
$pdo->exec("INSERT INTO cycles (started_at) VALUES (NOW())");

file_put_contents($stateDir . '/last_reset.txt', date('c') . " removed_files=$n\n", FILE_APPEND);
exit(0);
