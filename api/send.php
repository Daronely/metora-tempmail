<?php
require_once __DIR__ . '/../includes/mailer.php';

$u = require_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('method not allowed', 405);

$to = strtolower(trim($_POST['to'] ?? ''));
$subject = trim($_POST['subject'] ?? '');
$body = $_POST['body'] ?? '';

if (!valid_email($to)) json_error('آدرس گیرنده نامعتبر است');
if ($to === $u['email']) json_error('نمی‌توانید به خودتان ایمیل بزنید');
if (mb_strlen($subject) > 500) json_error('موضوع خیلی طولانی است');
if (mb_strlen($body) > 100000) json_error('متن ایمیل خیلی طولانی است');

// quota check (limit = PHP_INT_MAX for unlimited → never blocks)
$used = send_quota_used((int)$u['id']);
$limit = send_quota_limit($u);
if ($limit !== PHP_INT_MAX && $used >= $limit) {
    json_error(is_premium($u)
        ? "سهمیه ارسال پرمیوم ($limit ایمیل در هر ۶ ساعت) پر شده است."
        : "سهمیه ارسال رایگان ($limit ایمیل در هر ۶ ساعت) پر شده است. برای ارسال بیشتر پرمیوم شوید.", 429);
}

// attachments
$attachments = [];
$total = 0;
$files = $_FILES['attachments'] ?? null;
if ($files && is_array($files['name'])) {
    $count = count($files['name']);
    if ($count > att_limit($u)) json_error('حداکثر ' . att_limit($u) . ' پیوست در هر ایمیل' . (is_premium($u) ? '' : ' (پرمیوم: ۵)'));
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $one = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
        if ($one['size'] > MAX_UPLOAD_BYTES) json_error('حجم هر فایل حداکثر ' . MAX_UPLOAD_MB . ' مگابایت است');
        $total += $one['size'];
        if ($total > MAX_UPLOAD_BYTES * 2) json_error('مجموع پیوست‌ها نباید از ' . (MAX_UPLOAD_MB * 2) . ' مگابایت بگذرد');
        try { $attachments[] = store_attachment($one); }
        catch (RuntimeException $e) { json_error('ذخیره فایل ناموفق: ' . $e->getMessage()); }
    }
}

$mail = ['from' => $u['email'], 'to' => $to, 'subject' => $subject, 'body' => $body];
$ok = build_mime($mail, $attachments);
if (!$ok) json_error('ارسال ایمیل ناموفق بود (MTA خطا داد)', 500);

save_sent_copy((int)$u['id'], $mail, $attachments);
db()->prepare("INSERT INTO send_log (account_id, cycle_id) VALUES (?,?)")
    ->execute([$u['id'], current_cycle_id()]);

json_out(['ok' => true, 'sent_to' => $to,
          'quota_left' => $limit === PHP_INT_MAX ? null : max(0, $limit - $used - 1)]);
