<?php
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$folder = ($_GET['folder'] ?? 'inbox') === 'sent' ? 'sent' : 'inbox';

$stmt = db()->prepare("SELECT m.id, m.folder, m.from_addr, m.to_addr, m.subject,
        m.size_bytes, m.is_read, m.created_at,
        (SELECT COUNT(*) FROM attachments a WHERE a.message_id = m.id) AS att_count
        FROM messages m WHERE m.account_id = ? AND m.folder = ?
        ORDER BY m.created_at DESC LIMIT 200");
$stmt->execute([$u['id'], $folder]);
$rows = $stmt->fetchAll();

// enforce inbox retention limit (oldest beyond limit are pruned)
if ($folder === 'inbox') {
    $limit = inbox_limit($u);
    if (count($rows) > $limit) {
        $drop = array_slice($rows, $limit);
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $drop));
        db()->exec("DELETE FROM messages WHERE id IN ($ids)");
        $rows = array_slice($rows, 0, $limit);
    }
}

json_out(['ok' => true, 'folder' => $folder, 'messages' => $rows]);
