<?php
require_once __DIR__ . '/../includes/helpers.php';

$u = current_user();
if (!$u) json_out(['ok' => true, 'authenticated' => false]);

$cycle = current_cycle_id();
json_out([
    'ok' => true,
    'authenticated' => true,
    'email' => $u['email'],
    'username' => $u['username'],
    'role' => $u['role'],
    'premium' => is_premium($u),
    'premium_until' => $u['premium_until'],
    'unlimited' => is_unlimited($u),
    'quota' => [
        'send_used' => send_quota_used((int)$u['id']),
        'send_limit' => quota_display(send_quota_limit($u)),
        'inbox_limit' => quota_display(inbox_limit($u)),
        'att_limit' => quota_display(att_limit($u)),
        'max_upload_mb' => MAX_UPLOAD_MB,
    ],
    'cycle_id' => $cycle,
    'lang' => current_lang(),
    'dir' => SUPPORTED_LANGS[current_lang()],
    'price' => (int)get_setting('premium_price', PREMIUM_PRICE),
    'telegram' => SUPPORT_TELEGRAM,
    'phone' => SUPPORT_PHONE,
]);
