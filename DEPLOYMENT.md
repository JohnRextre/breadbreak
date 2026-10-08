# BreadBreak — InfinityFree Deployment Guide

The app deploys as a **subfolder install** (`htdocs/BreadBreak/`), which matches the
committed `BASE_URL = '/BreadBreak'` — **zero path changes required**.

Requirements: PHP ≥ 8.0 (InfinityFree ships 8.x), MySQL (included in plan).

---

## Step 1 — Upload the files

Upload the whole project into `htdocs/BreadBreak/` (git clone, zip, or File Manager).

- `vendor/phpmailer/` **must be included** — Gmail SMTP transport depends on it.
- Do **not** upload your dev `config/local.php` — create a fresh one on the server (Step 3).
- `storage/outbox/` is gitignored; the mailer auto-creates it (only used if SMTP is
  not configured). If email fails to save, create the folder manually (0755).

## Step 2 — Create and import the database

1. Client Area → **Databases** → create a MySQL database (note host/name/user/password).
2. Open **phpMyAdmin** → Import → `database/breadbreak_db.sql` (~6 MB — within limits).

The dump is fresh: **33 tables**, full schema + data, every existing account already
has `email_verified_at` set. **No migration scripts needed on the server**
(`database/` is web-blocked anyway — migrations are CLI-only).

## Step 3 — Create `config/local.php` on the server

Copy `config/local.php.example` → `config/local.php` and fill in every section:

```php
return [
    'db' => [ /* host/name/user/password from Step 2 */ ],
    'mail' => [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => 'yourgmail@gmail.com',
        'smtp_password' => 'your Gmail app password',   // no spaces
        'from_address' => 'yourgmail@gmail.com',
        'from_name' => 'BreadBreak',
    ],
    'app' => [
        'base_url' => 'https://YOUR-SITE.infinityfreeapp.com/BreadBreak',
    ],
    'xendit' => [ /* Step 4 */ ],
];
```

This file is gitignored and web-blocked (403) — credentials never leave the server.

## Step 4 — Xendit production credentials (CRITICAL)

The committed defaults are **test mode + ngrok** and the webhook token is a
placeholder — **the placeholder makes Xendit webhook verification get skipped
entirely**, which lets anyone fake a paid order. Before going live:

1. Xendit Dashboard → **Settings → Webhooks** → copy/regenerate the
   **Webhook Verification Token** → put it in `local.php` → `xendit.webhook_token`.
2. Xendit Dashboard → **Settings → Developers → API keys** → use the
   **live** secret key (`xnd_production_…`) → `xendit.secret_key`.
   *(The committed `xnd_development_…` test key was shared in git — treat it as
   compromised for production and never reuse it live.)*
3. In the Xendit webhook settings, set the URL to:
   `https://YOUR-SITE.infinityfreeapp.com/BreadBreak/webhook/xendit.php`
4. Return URLs go in `local.php` (`return_success_url` / `return_failure_url`) —
   the checkout sends them per-request, no dashboard entry needed.

## Step 5 — Post-deploy smoke checklist

- [ ] Homepage loads (https) — 200
- [ ] Register a fresh account → verification email arrives (Gmail SMTP) →
      click link → **sign in works**
- [ ] Login as unverified user → "email not verified" + **Resend** works
- [ ] Forgot password → reset email → set new password → login with it
- [ ] Admin login → System Logs + Inventory Audit History pages load
- [ ] Customer browse → cart → checkout page renders the Xendit payment
- [ ] Xendit dashboard → *Send test webhook* → order status updates
- [ ] Sensitive paths return **403**:
      `/BreadBreak/config/local.php`, `/BreadBreak/database/`,
      `/BreadBreak/storage/`, `/BreadBreak/.git/`
- [ ] Webhook endpoint reachable: `POST /BreadBreak/webhook/xendit.php` → not 403

### If the site returns 500 right after upload

Edit the root `.htaccess` and **remove the `Options -Indexes` line** — some shared
hosts forbid `Options` and abort the whole request. (InfinityFree disables directory
listings by default, so nothing is lost.)

### If something else breaks

- PHP errors → Control Panel → **Error Logs** (also check `error_log` via FTP).
- Mail failures are logged as `mailer: SMTP send failed — …`.
- `http://` links or wrong host → fix `app.base_url` in `config/local.php`.

---

### Notes

- The app never uses PHP `mail()` (restricted on InfinityFree) — all mail goes
  through Gmail SMTP; when `smtp_host` is empty, messages are filed to
  `storage/outbox/` instead of being sent.
- Local development is unchanged: without `config/local.php` everything falls
  back to defaults (file-outbox mail, localhost DB, test Xendit values).
