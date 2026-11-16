<?php
/**
 * Scheduled messages API.
 * Rules:
 *   - one scheduled send per user per calendar day (Tehran time)
 *   - max 5 pending scheduled messages per user (older pending auto-dropped by cron)
 *   - attachments allowed but each file <= 1 MB, max 1 file
 */
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$pdo = db();

/** Tehran "today" as Y-m-d (server stores UTC-era naive times; Tehran = server + 3:30) */
function tehran_today(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT id, to_addr, subject, body_text, send_at, status, sent_at, created_at,
                                  (att_json IS NOT NULL AND att_json != '[]') has_att
                           FROM scheduled_messages WHERE account_id = ? AND status = 'pending'
                           ORDER BY send_at ASC LIMIT 20");
    $stmt->execute([$u['id']]);
    json_out(['ok' => true, 'scheduled' => $stmt->fetchAll(),
              'today' => tehran_today(), 'now' => date('Y-m-d H:i:s')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($in['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM scheduled_messages WHERE id=? AND account_id=? AND status='pending'");
    $stmt->execute([$id, $u['id']]);
    if ($stmt->fetch()) {
        $row = $pdo->prepare("SELECT att_json FROM scheduled_messages WHERE id=?");
        $row->execute([$id]);
        $att = json_decode($row->fetch()['att_json'] ?? '[]', true) ?: [];
        foreach ($att as $a) @unlink(STORAGE_DIR . '/' . $a['path']);
        $pdo->prepare("DELETE FROM scheduled_messages WHERE id=?")->execute([$id]);
        json_out(['ok' => true]);
    }
    json_error('زمان‌بندی یافت نشد یا قبلاً ارسال شده');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $to = strtolower(trim($in['to'] ?? ''));
    $subject = trim($in['subject'] ?? '');
    $body = (string)($in['body'] ?? '');
    $send_at = trim($in['send_at'] ?? '');

    if (!valid_email($to)) json_error('آدرس گیرنده نامعتبر است');
    if ($to === $u['email']) json_error('نمی‌توانید به خودتان ایمیل بزنید');
    if (mb_strlen($subject) > 500) json_error('موضوع خیلی طولانی است');
    if (mb_strlen($body) > 100000) json_error('متن ایمیل خیلی طولانی است');

    // validate send_at (Y-m-d H:i) — must be in the future and within 90 days
    $dt = DateTime::createFromFormat('Y-m-d H:i', $send_at);
    if (!$dt) json_error('قالب زمان نامعتبر است (مثال: 2026-11-17 09:30)');
    $tehranNow = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    if ($dt <= $tehranNow) json_error('زمان ارسال باید در آینده باشد');
    if ($dt > (clone $tehranNow)->modify('+90 days')) json_error('زمان ارسال حداکثر می‌تواند ۹۰ روز بعد باشد');

    // rule: only one scheduled message per calendar day (Tehran)
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM scheduled_messages
                           WHERE account_id = ? AND status = 'pending' AND DATE(send_at) = ?");
    $stmt->execute([$u['id'], $dt->format('Y-m-d')]);
    if ((int)$stmt->fetch()['c'] > 0) {
        json_error('برای این روز قبلاً یک ایمیل زمان‌بندی کرده‌اید؛ هر روز فقط یک ایمیل زمان‌بندی‌شده مجاز است');
    }

    // rule: max 5 pending; drop the oldest beyond the limit
    $stmt = $pdo->prepare("SELECT id FROM scheduled_messages
                           WHERE account_id = ? AND status = 'pending' ORDER BY send_at ASC");
    $stmt->execute([$u['id']]);
    $pending = $stmt->fetchAll(PDO::FETCH_COLUMN);
    while (count($pending) >= 5) {
        $dropId = array_shift($pending);
        $row = $pdo->prepare("SELECT att_json FROM scheduled_messages WHERE id=?");
        $row->execute([$dropId]);
        $att = json_decode($row->fetch()['att_json'] ?? '[]', true) ?: [];
        foreach ($att as $a) @unlink(STORAGE_DIR . '/' . $a['path']);
        $pdo->prepare("DELETE FROM scheduled_messages WHERE id=?")->execute([$dropId]);
    }

    // attachments: max 1 file, each <= 1 MB (data URI)
    $attJson = '[]';
    if (!empty($in['attachment'])) {
        $att = $in['attachment'];
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $att['data'] ?? '', $m)) {
            $data = base64_decode($m[2]);
            if ($data === false || strlen($data) < 16) json_error('فایل پیوست نامعتبر است');
            if (strlen($data) > 1048576) json_error('حجم پیوست ایمیل زمان‌بندی‌شده حداکثر ۱ مگابایت است');
            $dir = STORAGE_DIR . '/sched';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $name = preg_replace('/[^\w.\- ]/u', '_', mb_substr($att['name'] ?: 'file', 0, 80));
            $rel = 'sched/' . bin2hex(random_bytes(12)) . '-' . $name;
            if (file_put_contents(STORAGE_DIR . '/' . $rel, $data) === false) json_error('ذخیره پیوست ناموفق بود');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $attJson = json_encode([['name' => $att['name'], 'path' => $rel,
                                     'mime' => $finfo->buffer($data) ?: 'application/octet-stream',
                                     'size' => strlen($data)]]);
        } else {
            json_error('قالب پیوست نامعتبر است');
        }
    }

    // store send_at in Tehran wall-clock (cron converts when comparing)
    $pdo->prepare("INSERT INTO scheduled_messages (account_id, to_addr, subject, body_text, att_json, send_at)
                   VALUES (?,?,?,?,?,?)")
        ->execute([$u['id'], $to, $subject, $body, $attJson, $dt->format('Y-m-d H:i:s')]);

    json_out(['ok' => true, 'send_at' => $dt->format('Y-m-d H:i:s')]);
}

json_error('method not allowed', 405);
