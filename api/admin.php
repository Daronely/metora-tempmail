<?php
require_once __DIR__ . '/../includes/helpers.php';

/** content-kind buckets for cleanup & heavy-message filtering */
const MIME_KINDS = [
    'image'  => ['label' => 'عکس‌ها',        'like' => 'image/%'],
    'video'  => ['label' => 'ویدیوها',       'like' => 'video/%'],
    'audio'  => ['label' => 'صداها',         'like' => 'audio/%'],
    'doc'    => ['label' => 'اسناد',         'like' => '%pdf%'],
    'archive'=> ['label' => 'آرشیوها',       'like' => '%zip%'],
    'text'   => ['label' => 'متن‌ها',         'like' => 'text/%'],
];

$a = require_admin();
$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'stats';

    if ($action === 'csrf') {
        // panel fetches this at boot and echoes the token on every POST (X-CSRF)
        json_out(['ok' => true, 'csrf' => csrf_token()]);
    }

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
        $rows = $pdo->query("SELECT id, username, email, role, is_premium, premium_until, created_at, last_active_at,
                                    lang, override_send_limit, override_inbox_limit, override_att_limit
                             FROM accounts ORDER BY id DESC LIMIT 500")->fetchAll();
        // pending resource-request count per account (for badges in the panel)
        $req = $pdo->query("SELECT account_id, COUNT(*) c FROM resource_requests
                            WHERE status='pending' GROUP BY account_id")->fetchAll();
        $reqMap = [];
        foreach ($req as $r) $reqMap[$r['account_id']] = (int)$r['c'];
        foreach ($rows as &$r) $r['pending_requests'] = $reqMap[$r['id']] ?? 0;
        json_out(['ok' => true, 'accounts' => $rows]);
    }

    /* ── resource requests queue ── */
    if ($action === 'requests') {
        $status = in_array(($_GET['status'] ?? 'pending'), ['pending','approved','rejected','all'], true)
                ? $_GET['status'] : 'pending';
        $sql = "SELECT r.*, a.username, a.email, a.is_premium, a.created_at acc_created, a.last_active_at
                FROM resource_requests r JOIN accounts a ON a.id = r.account_id";
        $params = [];
        if ($status !== 'all') { $sql .= " WHERE r.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY r.created_at DESC LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_out(['ok' => true, 'requests' => $stmt->fetchAll()]);
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

    if ($action === 'heavy') {
        // heavy messages: biggest messages with attachment info, filterable by kind
        $kind = $_GET['kind'] ?? 'all';
        $min = max(0, (int)($_GET['min'] ?? 0));
        $sql = "SELECT m.id, m.from_addr, m.to_addr, m.subject, m.size_bytes, m.folder, m.created_at,
                       a.filename, a.mime, a.size_bytes att_size, a.file_path
                FROM messages m LEFT JOIN attachments a ON a.message_id = m.id
                WHERE m.size_bytes >= ?";
        $params = [$min];
        if ($kind !== 'all') {
            $sql .= " AND (a.mime LIKE ? OR a.mime IS NULL AND 0)";
            $params[] = MIME_KINDS[$kind]['like'] ?? '%';
        }
        $sql .= " ORDER BY m.size_bytes DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        // group attachments under their message
        $out = [];
        foreach ($rows as $r) {
            $id = $r['id'];
            if (!isset($out[$id])) {
                $out[$id] = ['id' => $id, 'from_addr' => $r['from_addr'], 'to_addr' => $r['to_addr'],
                             'subject' => $r['subject'], 'size_bytes' => (int)$r['size_bytes'],
                             'folder' => $r['folder'], 'created_at' => $r['created_at'], 'attachments' => []];
            }
            if ($r['filename'] !== null) {
                $out[$id]['attachments'][] = ['filename' => $r['filename'], 'mime' => $r['mime'],
                                               'size' => (int)$r['att_size'], 'path' => $r['file_path']];
            }
        }
        json_out(['ok' => true, 'messages' => array_values($out)]);
    }

    if ($action === 'files') {
        // file tree: storage usage grouped by extension + orphan detection
        $keep = [];
        foreach ($pdo->query("SELECT file_path, filename, mime, size_bytes, message_id FROM attachments")->fetchAll() as $r)
            $keep[$r['file_path']] = $r;
        $tree = []; $total = 0; $count = 0; $orphanBytes = 0; $orphanCount = 0;
        foreach (glob(STORAGE_DIR . '/att/*') as $f) {
            $rel = 'att/' . basename($f);
            $size = (int)@filesize($f); $total += $size; $count++;
            $ref = $keep[$rel] ?? null;
            $name = $ref ? $ref['filename'] : basename($f);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: '—';
            if (!isset($tree[$ext])) $tree[$ext] = ['ext' => $ext, 'count' => 0, 'bytes' => 0, 'orphan' => 0];
            $tree[$ext]['count']++; $tree[$ext]['bytes'] += $size;
            if (!$ref) { $tree[$ext]['orphan']++; $orphanCount++; $orphanBytes += $size; }
        }
        foreach (glob(STORAGE_DIR . '/sched/*') as $f) {
            $size = (int)@filesize($f); $total += $size; $count++;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION)) ?: '—';
            if (!isset($tree[$ext])) $tree[$ext] = ['ext' => $ext, 'count' => 0, 'bytes' => 0, 'orphan' => 0];
            $tree[$ext]['count']++; $tree[$ext]['bytes'] += $size;
        }
        usort($tree, fn($a, $b) => $b['bytes'] - $a['bytes']);
        json_out(['ok' => true, 'tree' => $tree, 'total_bytes' => $total, 'files' => $count,
                  'orphan_count' => $orphanCount, 'orphan_bytes' => $orphanBytes]);
    }

    if ($action === 'scheduled') {
        $stmt = $pdo->query("SELECT s.*, a.email FROM scheduled_messages s
                             JOIN accounts a ON a.id = s.account_id
                             ORDER BY (s.status='pending') DESC, s.send_at DESC LIMIT 200");
        json_out(['ok' => true, 'scheduled' => $stmt->fetchAll()]);
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

    if ($action === 'add_admin') {
        $email = strtolower(trim($in['email'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        if (!valid_email($email) || !str_ends_with($email, '@' . MAIL_DOMAIN))
            json_error('ایمیل ادمین باید روی دامنه @' . MAIL_DOMAIN . ' باشد');
        if (strlen($pass) < 8) json_error('رمز ادمین جدید باید حداقل ۸ کاراکتر باشد');
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        if ($existing = $stmt->fetch()) {
            // promote existing user to admin
            $pdo->prepare("UPDATE accounts SET role='admin', is_premium=1 WHERE id=?")->execute([$existing['id']]);
            json_out(['ok' => true, 'promoted' => true, 'id' => (int)$existing['id']]);
        }
        $username = explode('@', $email)[0];
        $pdo->prepare("INSERT INTO accounts (username, email, password_hash, role, is_premium)
                       VALUES (?,?,?,?,1)")
            ->execute([$username, $email,
                       password_hash($pass . PEPPER, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), 'admin']);
        json_out(['ok' => true, 'created' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'remove_admin') {
        $id = (int)($in['id'] ?? 0);
        if ($id === (int)$a['id']) json_error('نمی‌توانید خودتان را از ادمینی خارج کنید');
        // refuse if this is the last admin
        $c = (int)$pdo->query("SELECT COUNT(*) c FROM accounts WHERE role='admin'")->fetch()['c'];
        if ($c <= 1) json_error('حداقل یک ادمین باید بماند');
        $pdo->prepare("UPDATE accounts SET role='user', is_premium=0 WHERE id=? AND role='admin'")->execute([$id]);
        json_out(['ok' => true]);
    }

    if ($action === 'purge_kind') {
        // delete ONLY the attachments of the selected kind (not whole messages
        // that also carry other attachments). Messages left with zero
        // attachments stay as normal text mails.
        $kind = (string)($in['kind'] ?? '');
        if (!isset(MIME_KINDS[$kind])) json_error('نوع نامعتبر است');
        $like = MIME_KINDS[$kind]['like'];
        $stmt = $pdo->prepare("SELECT id, file_path, size_bytes FROM attachments WHERE mime LIKE ?");
        $stmt->execute([$like]);
        $rows = $stmt->fetchAll();
        $bytes = 0; $n = 0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                if (@unlink(STORAGE_DIR . '/' . $r['file_path'])) { $n++; $bytes += (int)$r['size_bytes']; }
                $pdo->prepare("DELETE FROM attachments WHERE id=?")->execute([$r['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('پاکسازی نیمه‌کاره — دوباره تلاش کنید', 500);
        }
        json_out(['ok' => true, 'deleted' => $n, 'bytes' => $bytes, 'mode' => 'attachments-only']);
    }

    if ($action === 'purge_texts') {
        // delete text-only messages (no attachments at all) — min size filter
        $min = max(0, (int)($in['min_kb'] ?? 0)) * 1024;
        $stmt = $pdo->prepare("SELECT m.id FROM messages m WHERE NOT EXISTS
                               (SELECT 1 FROM attachments a WHERE a.message_id = m.id)
                               AND COALESCE(m.size_bytes,0) >= ?");
        $stmt->execute([$min]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $inSql = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM messages WHERE id IN ($inSql)")->execute($ids);
        }
        json_out(['ok' => true, 'deleted' => count($ids)]);
    }

    if ($action === 'purge_orphan_files') {
        // delete files on disk that no message references
        $keep = [];
        foreach ($pdo->query("SELECT file_path FROM attachments")->fetchAll() as $r) $keep[$r['file_path']] = 1;
        $n = 0; $bytes = 0;
        foreach (glob(STORAGE_DIR . '/att/*') as $f) {
            $rel = 'att/' . basename($f);
            if (!isset($keep[$rel]) && @unlink($f)) { $n++; $bytes += (int)@filesize($f); }
        }
        json_out(['ok' => true, 'deleted' => $n, 'bytes' => $bytes]);
    }

    if ($action === 'purge_ext') {
        // delete attachment files of a given extension from disk + their attachments rows
        $ext = strtolower(trim((string)($in['ext'] ?? '')));
        if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) json_error('پسوند نامعتبر است');
        $stmt = $pdo->prepare("SELECT id, file_path, size_bytes FROM attachments WHERE file_path LIKE ?");
        $stmt->execute(['%.' . $ext]);
        $n = 0; $bytes = 0;
        foreach ($stmt->fetchAll() as $r) {
            if (@unlink(STORAGE_DIR . '/' . $r['file_path'])) { $n++; $bytes += (int)$r['size_bytes']; }
            $pdo->prepare("DELETE FROM attachments WHERE id=?")->execute([$r['id']]);
        }
        json_out(['ok' => true, 'deleted' => $n, 'bytes' => $bytes]);
    }

    if ($action === 'delete_sched') {
        $id = (int)($in['id'] ?? 0);
        $row = $pdo->prepare("SELECT att_json FROM scheduled_messages WHERE id=?");
        $row->execute([$id]);
        if ($r = $row->fetch()) {
            foreach (json_decode($r['att_json'] ?? '[]', true) ?: [] as $a) @unlink(STORAGE_DIR . '/' . $a['path']);
            $pdo->prepare("DELETE FROM scheduled_messages WHERE id=?")->execute([$id]);
        }
        json_out(['ok' => true]);
    }

    /* ── decide a resource request (approve applies the quota instantly) ── */
    if ($action === 'request_decide') {
        $id = (int)($in['id'] ?? 0);
        $approve = !empty($in['approve']);
        $note = trim((string)($in['note'] ?? ''));
        $stmt = $pdo->prepare("SELECT r.*, a.email, a.role FROM resource_requests r
                               JOIN accounts a ON a.id = r.account_id
                               WHERE r.id=? AND r.status='pending'");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if (!$req) json_error('درخواست یافت نشد یا قبلاً بررسی شده', 404);
        if ($req['role'] === 'admin') json_error('ادمین‌ها نیاز به ارتقا ندارند');

        if ($approve) {
            if ($req['kind'] === 'premium') {
                // grant 30 days of premium
                $pdo->prepare("UPDATE accounts SET is_premium=1,
                               premium_until = GREATEST(COALESCE(premium_until, NOW()), NOW()) + INTERVAL 30 DAY
                               WHERE id=?")->execute([$req['account_id']]);
            } else {
                $col = ['send' => 'override_send_limit', 'inbox' => 'override_inbox_limit', 'att' => 'override_att_limit'][$req['kind']];
                $pdo->prepare("UPDATE accounts SET $col = ? WHERE id=?")
                    ->execute([(int)$req['amount'], $req['account_id']]);
            }
        }
        $pdo->prepare("UPDATE resource_requests SET status=?, admin_note=?, handled_at=NOW() WHERE id=?")
            ->execute([$approve ? 'approved' : 'rejected', $note, $id]);
        json_out(['ok' => true]);
    }

    /* ── per-user quota overrides (independent of the request queue) ── */
    if ($action === 'set_overrides') {
        $id = (int)($in['id'] ?? 0);
        if ($id === (int)$a['id']) json_error('برای خودتان override لازم نیست — ادمین هستید');
        $sets = []; $params = [];
        foreach (['override_send_limit', 'override_inbox_limit', 'override_att_limit'] as $col) {
            if (array_key_exists($col, $in)) {
                $v = $in[$col];
                $sets[] = "$col = ?";
                $params[] = ($v === null || $v === '' || (int)$v <= 0) ? null : (int)$v;
            }
        }
        if ($sets) {
            $params[] = $id;
            $pdo->prepare("UPDATE accounts SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
        }
        json_out(['ok' => true]);
    }

    json_error('unknown action', 422);
}

json_error('method not allowed', 405);
