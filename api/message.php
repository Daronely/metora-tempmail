<?php
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM messages WHERE id = ? AND account_id = ?");
$stmt->execute([$id, $u['id']]);
$m = $stmt->fetch();
if (!$m) json_error('پیام یافت نشد', 404);

// mark read (inbox only) — unless peek=1 (OTP auto-detection must not mark read)
$peek = isset($_GET['peek']);
if (!$peek && $m['folder'] === 'inbox' && !$m['is_read']) {
    db()->prepare("UPDATE messages SET is_read = 1 WHERE id = ?")->execute([$id]);
    $m['is_read'] = 1;
}

$att = db()->prepare("SELECT id, filename, mime, size_bytes FROM attachments WHERE message_id = ?");
$att->execute([$id]);

json_out(['ok' => true, 'message' => $m, 'attachments' => $att->fetchAll()]);
