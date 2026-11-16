<?php
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    // delete attachments on disk
    $stmt = db()->prepare("SELECT a.file_path FROM attachments a
                           JOIN messages m ON m.id = a.message_id
                           WHERE m.id = ? AND m.account_id = ?");
    $stmt->execute([$id, $u['id']]);
    foreach ($stmt->fetchAll() as $a) @unlink(STORAGE_DIR . '/' . $a['file_path']);
    db()->prepare("DELETE FROM messages WHERE id = ? AND account_id = ?")->execute([$id, $u['id']]);
    json_out(['ok' => true]);
}

json_error('method not allowed', 405);
