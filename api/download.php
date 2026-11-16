<?php
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$mid = (int)($_GET['mid'] ?? 0);
$attId = (int)($_GET['att'] ?? 0);

$stmt = db()->prepare("SELECT a.*, m.account_id FROM attachments a
                       JOIN messages m ON m.id = a.message_id
                       WHERE a.id = ? AND m.id = ? AND m.account_id = ?");
$stmt->execute([$attId, $mid, $u['id']]);
$a = $stmt->fetch();
if (!$a) { http_response_code(404); exit('not found'); }

$path = STORAGE_DIR . '/' . $a['file_path'];
if (!is_file($path)) { http_response_code(404); exit('file missing'); }

header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . rawurlencode($a['filename']) . '"');
readfile($path);
