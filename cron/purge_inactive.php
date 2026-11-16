#!/usr/local/bin/php -q
<?php
/**
 * Cron: purge_inactive — daily.
 * Deletes accounts (and their data) inactive for more than TTL_DAYS days.
 * Admin accounts are never purged.
 */
$home = '/home/YOURUSER/temp.example.com';
require_once $home . '/includes/config.php';
require_once $home . '/includes/helpers_standalone.php';

$pdo = cli_db();
$stateDir = STATE_DIR;
if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);

$stmt = $pdo->prepare("SELECT id, email FROM accounts
                       WHERE role != 'admin'
                       AND last_active_at < (NOW() - INTERVAL " . TTL_DAYS . " DAY)");
$stmt->execute();
$deleted = 0;
foreach ($stmt->fetchAll() as $acc) {
    $f = $pdo->prepare("SELECT file_path FROM attachments a JOIN messages m ON m.id=a.message_id WHERE m.account_id=?");
    $f->execute([$acc['id']]);
    foreach ($f->fetchAll() as $r) @unlink(STORAGE_DIR . '/' . $r['file_path']);
    $pdo->prepare("DELETE FROM accounts WHERE id=?")->execute([$acc['id']]);
    $deleted++;
}

file_put_contents($stateDir . '/last_purge.txt', date('c') . " deleted_accounts=$deleted\n", FILE_APPEND);
exit(0);
