<?php
require_once __DIR__ . '/../includes/helpers.php';

$a = require_admin();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'stats';

    if ($action === 'settings') {
        $keys = ['send_limit_free','send_limit_premium','send_limit_admin','inbox_limit_free',
                 'inbox_limit_premium','inbox_limit_admin','att_limit_free','att_limit_premium',
                 'att_limit_admin','premium_price','admin_unlimited'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = get_setting($k, null);
            if ($out[$k] === null && $k === 'admin_unlimited') $out[$k] = '1';
        }
        json_out(['ok' => true, 'settings' => $out]);
    }

    if ($action === 'orphans') {
        // attachments on disk not referenced by any message
        $rows = $pdo->query("SELECT file_path FROM attachments")->fetchAll();
        $keep = array_column($rows, 'file_path');
        $orphan = [];
        foreach (glob(STORAGE_DIR . '/att/*') as $f) {
            $rel = 'att/' . basename($f);
            if (!in_array($rel, $keep, true)) $orphan[] = $rel;
        }
        json_out(['ok' => true, 'orphans' => $orphan, 'count' => count($orphan)]);
    }

    if ($action === 'stats') {
        $orphanCount = 0;
        $rows = $pdo->query("SELECT file_path FROM attachments")->fetchAll();
        $keep = array_column($rows, 'file_path');
        foreach (glob(STORAGE_DIR . '/att/*') as $f) {
            if (!in_array('att/' . basename($f), $keep, true)) $orphanCount++;
        }
        $stats = [
            'accounts' => (int)$pdo->query("SELECT COUNT(*) c FROM accounts")->fetch()['c'],
            'premium' => (int)$pdo->query("SELECT COUNT(*) c FROM accounts WHERE is_premium=1")->fetch()['c'],
            'messages' => (int)$pdo->query("SELECT COUNT(*) c FROM messages")->fetch()['c'],
            'inbox' => (int)$pdo->query("SELECT COUNT(*) c FROM messages WHERE folder='inbox'")->fetch()['c'],
            'sent' => (int)$pdo->query("SELECT COUNT(*) c FROM messages WHERE folder='sent'")->fetch()['c'],
            'attachments' => (int)$pdo->query("SELECT COUNT(*) c FROM attachments")->fetch()['c'],
            'sends_this_cycle' => (int)$pdo->query("SELECT COUNT(*) c FROM send_log WHERE cycle_id=" . current_cycle_id())->fetch()['c'],
            'cycles' => (int)$pdo->query("SELECT COUNT(*) c FROM cycles")->fetch()['c'],
            'orphans' => $orphanCount,
            'db_size' => (int)$pdo->query("SELECT SUM(data_length + index_length) s FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetch()['s'],
        ];
        json_out(['ok' => true, 'stats' => $stats]);
    }

    if ($action === 'accounts') {
        $rows = $pdo->query("SELECT id, username, email, role, is_premium, premium_until, created_at, last_active_at
                             FROM accounts ORDER BY id DESC LIMIT 500")->fetchAll();
        json_out(['ok' => true, 'accounts' => $rows]);
    }

    if ($action === 'messages') {
        $stmt = $pdo->prepare("SELECT m.*, a.filename att_name FROM messages m
            LEFT JOIN attachments a ON a.message_id = m.id
            ORDER BY m.created_at DESC LIMIT 300");
        $stmt->execute();
        json_out(['ok' => true, 'messages' => $stmt->fetchAll()]);
    }

    if ($action === 'message') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT m.*, a.id att_id, a.filename, a.mime, a.size_bytes att_size
            FROM messages m LEFT JOIN attachments a ON a.message_id = m.id
            WHERE m.id = ?");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        if (!$rows) json_error('یافت نشد', 404);
        $msg = $rows[0];
        unset($msg['att_id'], $msg['filename'], $msg['mime'], $msg['att_size']);
        $atts = array_map(function ($r) {
            return ['id' => $r['att_id'], 'filename' => $r['filename'], 'mime' => $r['mime'], 'size' => $r['att_size']];
        }, array_filter($rows, fn($r) => $r['att_id'] !== null));
        json_out(['ok' => true, 'message' => $msg, 'attachments' => array_values($atts)]);
    }

    json_error('unknown action', 422);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    if ($action === 'premium') {
        $id = (int)($in['id'] ?? 0);
        $on = !empty($in['on']);
        $days = (int)($in['days'] ?? 30);
        $until = $on ? date('Y-m-d H:i:s', strtotime("+$days days")) : null;
        $pdo->prepare("UPDATE accounts SET is_premium=?, premium_until=? WHERE id=? AND role!='admin'")
            ->execute([$on ? 1 : 0, $until, $id]);
        json_out(['ok' => true]);
    }

    if ($action === 'delete_account') {
        $id = (int)($in['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT a.file_path FROM attachments a JOIN messages m ON m.id=a.message_id
                               JOIN accounts acc ON acc.id=m.account_id WHERE acc.id=?");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $r) @unlink(STORAGE_DIR . '/' . $r['file_path']);
        $pdo->prepare("DELETE FROM accounts WHERE id=? AND role!='admin'")->execute([$id]);
        json_out(['ok' => true]);
    }

    if ($action === 'delete_message') {
        $id = (int)($in['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT file_path FROM attachments WHERE message_id=?");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $r) @unlink(STORAGE_DIR . '/' . $r['file_path']);
        $pdo->prepare("DELETE FROM messages WHERE id=?")->execute([$id]);
        json_out(['ok' => true]);
    }

    if ($action === 'save_settings') {
        $allowed = ['send_limit_free','send_limit_premium','send_limit_admin','inbox_limit_free',
                    'inbox_limit_premium','inbox_limit_admin','att_limit_free','att_limit_premium',
                    'att_limit_admin','premium_price'];
        foreach ($allowed as $k) {
            if (!isset($in[$k])) continue;
            $v = (int)$in[$k];
            if ($v < 0) $v = 0;
            if ($v > 1000000) $v = 1000000;
            set_setting($k, $v);
        }
        if (isset($in['admin_unlimited'])) {
            set_setting('admin_unlimited', !empty($in['admin_unlimited']) ? '1' : '0');
        }
        json_out(['ok' => true]);
    }

    if ($action === 'reset_now') {
        // full reset — same as the 6h cron (messages wiped, accounts kept)
        $stmt = $pdo->query("SELECT file_path FROM attachments");
        foreach ($stmt->fetchAll() as $r) @unlink(STORAGE_DIR . '/' . $r['file_path']);
        $pdo->exec("DELETE FROM attachments");
        $pdo->exec("DELETE FROM messages");
        $pdo->exec("DELETE FROM send_log");
        $pdo->exec("INSERT INTO cycles (started_at) VALUES (NOW())");
        json_out(['ok' => true, 'mode' => 'all']);
    }

    if ($action === 'reset_self') {
        // wipe ONLY the admin's own inbox/sent (frees storage without touching users)
        $stmt = $pdo->prepare("SELECT a.file_path FROM attachments a
                               JOIN messages m ON m.id = a.message_id
                               WHERE m.account_id = ?");
        $stmt->execute([$a['id']]);
        foreach ($stmt->fetchAll() as $r) @unlink(STORAGE_DIR . '/' . $r['file_path']);
        $pdo->prepare("DELETE FROM messages WHERE account_id = ?")->execute([$a['id']]);
        json_out(['ok' => true, 'mode' => 'self']);
    }

    if ($action === 'reset_orphans') {
        // delete attachment files on disk not referenced by any message
        $rows = $pdo->query("SELECT file_path FROM attachments")->fetchAll();
        $keep = array_column($rows, 'file_path');
        $n = 0;
        foreach (glob(STORAGE_DIR . '/att/*') as $f) {
            if (!in_array('att/' . basename($f), $keep, true) && @unlink($f)) $n++;
        }
        json_out(['ok' => true, 'mode' => 'orphans', 'deleted' => $n]);
    }

    if ($action === 'purge_inactive_now') {
        // delete accounts inactive for more than TTL_DAYS days (admins excluded)
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE role != 'admin'
                               AND last_active_at < (NOW() - INTERVAL " . TTL_DAYS . " DAY)");
        $stmt->execute();
        $deleted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $f = $pdo->prepare("SELECT file_path FROM attachments a JOIN messages m ON m.id=a.message_id WHERE m.account_id=?");
            $f->execute([$id]);
            foreach ($f->fetchAll() as $r) @unlink(STORAGE_DIR . '/' . $r['file_path']);
            $pdo->prepare("DELETE FROM accounts WHERE id=?")->execute([$id]);
            $deleted++;
        }
        json_out(['ok' => true, 'mode' => 'inactive', 'deleted' => $deleted]);
    }

    if ($action === 'change_password') {
        $new = $in['password'] ?? '';
        if (strlen($new) < 8) json_error('رمز جدید باید حداقل ۸ کاراکتر باشد');
        if ($new === ADMIN_SEED_PASSWORD) json_error('رمز جدید نمی‌تواند رمز اولیه باشد');
        $pdo->prepare("UPDATE accounts SET password_hash=? WHERE id=?")
            ->execute([password_hash($new . PEPPER, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $a['id']]);
        json_out(['ok' => true]);
    }

    json_error('unknown action', 422);
}

json_error('method not allowed', 405);
