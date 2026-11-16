<div align="center">

# ⚡ متِمپ — Metora TempMail

**ایمیل موقتی که واقعاً کار می‌کنه** — بفرست، بگیر، پیوست کن. بدون اسپم، بدون دردسر.

[![Live Demo](https://img.shields.io/badge/%F0%9F%94%B4-Live-temp.metora.ir-3CF2D3?style=for-the-badge)](https://temp.metora.ir)
![PHP](https://img.shields.io/badge/PHP-8.1+-8892BF?style=for-the-badge&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-3CF2D3?style=for-the-badge)

*یک سرویس ایمیل موقت کامل روی هاست اشتراکی cPanel معمولی — بدون سرور اختصاصی، بدون سرویس ابری.*

</div>

---

## ✨ چرا متِمپ؟

بیشتر سرویس‌های ایمیل موقت فقط **دریافت** دارند. متِمپ فرق دارد:

| قابلیت | متِمپ | سرویس‌های معمول |
|:---|:---:|:---:|
| آدرس اختصاصی `@metora.ir` | ✅ | ✅ |
| **ارسال** ایمیل واقعی | ✅ | ❌ |
| دریافت ایمیل واقعی | ✅ | ✅ |
| پیوست تا ۲ مگابایت | ✅ | بعضی‌ها |
| پاسخ‌دادن (Reply) | ✅ | ❌ |
| حذف خودکار هر ۶ ساعت | ✅ | ❌ |
| UI فارسی و شیک | ✅ | ❌ |

> 🧹 **فلسفه متِمپ:** هیچ‌چیز نمی‌ماند. هر ۶ ساعت کل صندوق‌ها پاک می‌شوند؛ فقط آدرس و رمز تو می‌ماند. اگر ۱۰ روز سراغش نروی، کل اکانت هم پاک می‌شود — مثل اینکه هرگز وجود نداشته.

---

## 🚀 امکانات

- **ثبت‌نام ۱۰ ثانیه‌ای** — فقط نام کاربری و رمز؛ نه شماره‌ی تلفن، نه کپچا، نه وریفای
- **Mail client کامل** — Inbox و Sent، خواندن، حذف، پاسخ، پیوست، دانلود
- **سهمیه هوشمند** — رایگان: ۱۰ ارسال در هر سیکل · پرمیوم: ۱۰۰ ارسال + صندوق بزرگ‌تر
- **پلن پرمیوم $۱۰/ماه** — فعال‌سازی از طریق تلگرام [`@daronelydev`](https://t.me/daronelydev)
- **پنل ادمین** — آمار زنده، مدیریت اکانت‌ها (پرمیوم‌کردن / حذف)، مشاهده و حذف ایمیل‌ها، ریست دستی
- **دنیالی حذف خودکار**:
  - 🕐 هر **۶ ساعت**: همه‌ی پیام‌ها و پیوست‌ها پاک، اکانت‌ها سالم
  - 📅 هر **۱۰ روز بی‌فعالیت**: اکانت به‌طور کامل حذف می‌شود
- **امنیت**: رمزها با bcrypt + pepper، session hardening، protected includes، prepared statements همه‌جا

---

## 🏗️ معماری

```
                       ┌──────────────────────────────────┐
   External mail ─────▶│  MX (mail.metora.ir, Exim)       │
                       │  catch-all → tempmail@metora.ir  │
                       └──────────────┬───────────────────┘
                                      │ IMAP (every 1 min)
                                      ▼
   ┌────────────┐   cron    ┌─────────────────────┐
   │ Browser UI │◀─ REST ──▶│  PHP 8.1 (LiteSpeed)│◀── MySQL
   │ (SPA, RTL) │           │  temp.metora.ir     │     youruser_tempmail
   └────────────┘           └──────────┬──────────┘
                                       │ mail() → sendmail
                                       ▼
                                 Outgoing email
```

| مسیر | توضیح |
|:---|:---|
| `/` | اپ تک‌صفحه‌ای (Landing + Mail client) |
| `/api/*.php` | REST endpoints (auth, send, messages, admin, …) |
| `/cron/imap_poll.php` | هر دقیقه: ایمیل‌های catch-all را به inbox کاربران منتقل می‌کند |
| `/cron/reset_cycle.php` | هر ۶ ساعت: پاک‌سازی کامل پیام‌ها + شروع سیکل جدید |
| `/cron/purge_inactive.php` | روزانه: حذف اکانت‌های ۱۰ روزه بی‌فعالیت |
| `/admin/` | پنل ادمین |
| `pipe/pipe.php` | جایگزین pipe-to-program برای هاست‌هایی که pipe دارند |

---

## 🔧 نصب روی cPanel (۵ دقیقه)

### پیش‌نیازها
- هاست cPanel با PHP 8.1+ (افزونه‌های `imap`, `mysqli`, `mbstring`, `fileinfo`) — روی اکثر هاست‌ها فعاله
- یک دامنه که MX اون به هاست اشاره کنه

### مراحل

1. **فایل‌ها را آپلود کنید** — محتوای ریپو را در docroot ساب‌دامین بگذارید (مثلاً `~/temp.example.com`)

2. **دیتابیس بسازید** — از cPanel → MySQL® Databases یک دیتابیس و یوزر بسازید و ALL PRIVILEGES بدهید

3. **کانفیگ** — فایل `includes/config.sample.php` را به `config.php` کپی و مقادیر را پر کنید:
   ```php
   define('DB_NAME', 'cpuser_tempmail');
   define('DB_USER', 'cpuser_tempmail');
   define('DB_PASS', '••••');
   define('MAIL_DOMAIN', 'example.com');      // دامنه‌ی آدرس‌های ایمیل
   define('CATCHALL_USER', 'catchall@example.com');
   define('CATCHALL_PASS', '••••');
   define('PEPPER', 'یک-رشته‌ی-تصادفی-طولانی');
   ```

4. **صندوق catch-all بسازید** — از cPanel → Email Accounts یک ایمیل جدید (مثلاً `tempmail@example.com`) بسازید. رمزش را در `CATCHALL_PASS` بگذارید.

5. **Default Address** — از cPanel → Email → **Default Address** گزینه‌ی *Forward to Email Address* را روی همان صندوق بگذارید (یا با API: `Email::setdefaultaddress` با `fwdopt=fwd`)

6. **نصب** — یک‌بار `https://temp.example.com/setup.php` را باز کنید تا جدول‌ها ساخته و اکانت ادمین seed شود، **بعد فایل را حذف کنید!**

7. **Cron jobs** — از cPanel → Cron Jobs:
   ```
   * * * * *  /usr/local/bin/php -q ~/temp.example.com/cron/imap_poll.php
   0 0,6,12,18 * * *  /usr/local/bin/php -q ~/temp.example.com/cron/reset_cycle.php
   30 3 * * *  /usr/local/bin/php -q ~/temp.example.com/cron/purge_inactive.php
   ```

8. **مجوزها** — دایرکتوری‌های `storage/` و `state/` باید برای PHP قابل‌نوشسه باشند (0750 معمولاً کافیه)

> 🔐 **اولین کار بعد از نصب:** با اکانت ادمین لاگین کنید و از تب تنظیمات رمز را عوض کنید. seed اولیه در `config.php` → `ADMIN_SEED_PASSWORD` است.

---

## 📊 قوانین سهمیه

| | رایگان | پرمیوم ($۱۰/ماه) |
|:---|:---:|:---:|
| ارسال در هر سیکل ۶ ساعته | ۱۰ | ۱۰۰ |
| نگهداری ایمیل دریافتی | ۵۰ | ۵۰۰ |
| پیوست در هر ایمیل | ۱ | ۵ |
| حجم هر پیوست | ۲MB | ۲MB |
| ادمین | بدون محدودیت | — |

---

## 🗂️ ساختار دیتابیس

```
accounts   (id, username, email, password_hash, role, is_premium, premium_until, last_active_at)
messages   (id, account_id, folder[inbox|sent], from_addr, to_addr, subject, body_text, body_html, …)
attachments(id, message_id, filename, mime, size_bytes, file_path)
send_log   (id, account_id, cycle_id, created_at)     ← شمارنده‌ی سهمیه
cycles     (id, started_at)                            ← هر ریست ۶ ساعته یک ردیف
```

---

## 🛡️ امنیت

- رمزها: `bcrypt(cost 11)` + static pepper
- کوکی سشن: `HttpOnly`, `SameSite=Lax`, `Secure` (وقتی HTTPS)
- همه‌ی کوئری‌ها prepared statement
- فایل‌های پیوست با نام تصادفی hex خارج از docroot معتبر ذخیره می‌شوند
- `includes/` با `.htaccess` از دسترسی مستقیم بسته شده
- attachment MIME با `finfo` تشخیص داده می‌شود (نه از ورودی کاربر)

---

## 🤝 پشتیبانی

- **تلگرام:** [@daronelydev](https://t.me/daronelydev)
- **تلفن:** ۰۹۹۹۹۹۶۰۲۹۵
- ساخت [#Daronely](https://github.com/Daronely) — زیر چتر [Metora](https://metora.ir)

## 📄 لایسنس

MIT — آزادانه fork کنید، ولی اسپم نسازید 🙂
