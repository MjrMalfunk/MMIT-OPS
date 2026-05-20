# cPanel Deployment Readiness Notes (MMIT-OPS)

This document captures what this repository expects when deploying to cPanel hosting
(including `ops.midwestmanagedit.com`).

## 1) Required server-only files and secrets

- **Required:** `inc/config.php` (not in repo).
  - The app hard-fails with HTTP 500 if this file is missing.
  - Create it by copying `inc/config.sample.php` and filling secrets.
- Keep `APP_ENC_KEY_B64` set to a real 32-byte base64 key before production use.

## 2) Writable paths expected by the app

### Required writable

- `storage/onedrive/`
  - OneDrive token payloads are written here as encrypted JSON files.
  - App attempts to create this directory if missing, but cPanel ownership/ACLs must still permit writes.

### Recommended writable

- Path backing `MAIL_LOG_FILE` (default: repo root `ops-mail.log`).
  - If not writable, mail logging can fail silently depending on runtime permissions.

## 3) Database import / migration expectations

- There is at least one checked-in SQL patch in `db/`:
  - `db/2026-04-07-mmit-ops-package-restructure.sql`
- Several pages explicitly expect additional SQL migrations under a `sql/` folder
  (for example portal invites, reconciliation, products/services, audit trail, Syncro columns).
- Before go-live, verify all migration files referenced in UI messages are present in your deployment process and applied in order.

## 4) .htaccess and web server behavior

- The app does not require URL rewriting for core routing (uses direct `.php` paths).
- `storage/.htaccess` is auto-created with `Deny from all` to protect token storage.
- No repository-root `.htaccess` is required by code, but cPanel deployments should still enforce:
  - HTTPS redirect (app also enforces HTTPS in PHP)
  - PHP handler/version settings
  - optional hardening headers at Apache level

## 5) Cron expectations

- Recurring billing job entry point:
  - `cron/generate_recurring_invoices.php`
- Intended for CLI execution; accepts `--date=YYYY-MM-DD`.
- cPanel cron should invoke the PHP binary with an **absolute path** to this script in the deployed account.

## 6) Environment/path mismatches to confirm on ops.midwestmanagedit.com

- `BASE_URL` must exactly match deployment origin (including scheme):
  - `https://ops.midwestmanagedit.com`
- If deployed under a different docroot/subfolder, verify every generated `BASE_URL` link still resolves.
- Confirm these paths are correct for the hosting account:
  - absolute cron script path
  - writable directory ownership for `storage/` and mail log target
  - DB host/user/password/dbname in `inc/config.php`

## 7) Quick preflight checklist

1. Create `inc/config.php` from sample and populate all required secrets.
2. Apply DB schema + all referenced SQL migrations.
3. Verify `storage/onedrive/` exists and is writable by PHP.
4. Verify Stripe webhook endpoint URL points to:
   - `https://ops.midwestmanagedit.com/payments/webhook_stripe.php`
5. Configure cron with account-specific absolute paths.
6. Run smoke checks: login, client list, invoice list, payment page, webhook health page.
