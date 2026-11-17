<?php
/**
 * TempMail — Configuration
 * Copy config.sample.php to config.php and fill in your environment.
 */

// ── Database ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'qvjdxmzt_tempmail');   // cPanel prefix style: user_tempmail
define('DB_USER', 'qvjdxmzt_tempmail');
define('DB_PASS', 'Tm#2026190D3F8C');
define('DB_CHARSET', 'utf8mb4');

// ── Domain / App ─────────────────────────────────────────
define('APP_DOMAIN', 'temp.metora.ir');
define('MAIL_DOMAIN', 'metora.ir');        // domain used for the temp addresses
define('APP_URL', 'https://temp.metora.ir');
define('APP_NAME', 'Metora TempMail');

// ── Limits (free tier) ───────────────────────────────────
define('MAX_UPLOAD_MB', 2);               // max attachment size
define('MAX_UPLOAD_BYTES', MAX_UPLOAD_MB * 1048576);
define('INBOX_LIMIT_FREE', 50);           // stored received mails per account
define('SEND_LIMIT_FREE', 10);            // outgoing mails per 6h cycle
define('ATTACH_LIMIT_FREE', 1);           // attachments per mail (free)
define('PREMIUM_PRICE', 10);              // USD

// Premium (admin/premium) gets: unlimited inbox, 100 sends/cycle, 5 attachments
define('INBOX_LIMIT_PREMIUM', 500);
define('SEND_LIMIT_PREMIUM', 100);
define('ATTACH_LIMIT_PREMIUM', 5);

// ── Lifecycles ───────────────────────────────────────────
define('TTL_DAYS', 10);                   // account inactivity before deletion
define('RESET_HOURS', 6);                 // database reset cycle

// ── Admin ────────────────────────────────────────────────
// Account created/seeded by setup. Password must be changed after first login
// (installer refuses to run when the seed password is still active).
define('ADMIN_EMAIL', 'd@metora.ir');
define('ADMIN_SEED_PASSWORD', 'kingmayar');
define('SUPPORT_TELEGRAM', 'daronelydev');
define('SUPPORT_PHONE', '09999960295');

// ── Sessions ─────────────────────────────────────────────
define('SESSION_NAME', 'TMSESS');
define('SESSION_LIFETIME', 0);            // until browser closes

// ── Security ─────────────────────────────────────────────
define('PEPPER', 'mt#9f3$1x!Q');          // extra static secret — CHANGE THIS
define('BCRYPT_COST', 11);

// ── Cron bookkeeping (written by cron jobs) ──────────────
define('STATE_DIR', __DIR__ . '/../state');

// ── Local mail storage ───────────────────────────────────
define('STORAGE_DIR', __DIR__ . '/../storage');

// ── Catch-all mailbox (IMAP polling for incoming mail) ───
// The domain's Default Address must forward to this mailbox.
define('CATCHALL_USER', 'tempmail@metora.ir');
define('CATCHALL_PASS', 'change-me-mailbox-password');
