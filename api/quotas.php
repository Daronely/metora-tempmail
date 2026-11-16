<?php
/** Public quota/plan info for the landing page (no auth). */
require_once __DIR__ . '/../includes/helpers.php';

json_out([
    'ok' => true,
    'free' => [
        'send'  => (int)get_setting('send_limit_free', defined('SEND_LIMIT_FREE') ? SEND_LIMIT_FREE : 10),
        'inbox' => (int)get_setting('inbox_limit_free', defined('INBOX_LIMIT_FREE') ? INBOX_LIMIT_FREE : 50),
        'att'   => (int)get_setting('att_limit_free', defined('ATTACH_LIMIT_FREE') ? ATTACH_LIMIT_FREE : 1),
    ],
    'premium' => [
        'send'  => (int)get_setting('send_limit_premium', defined('SEND_LIMIT_PREMIUM') ? SEND_LIMIT_PREMIUM : 100),
        'inbox' => (int)get_setting('inbox_limit_premium', defined('INBOX_LIMIT_PREMIUM') ? INBOX_LIMIT_PREMIUM : 500),
        'att'   => (int)get_setting('att_limit_premium', defined('ATTACH_LIMIT_PREMIUM') ? ATTACH_LIMIT_PREMIUM : 5),
    ],
    'price' => (int)get_setting('premium_price', defined('PREMIUM_PRICE') ? PREMIUM_PRICE : 10),
    'domain' => MAIL_DOMAIN,
]);
