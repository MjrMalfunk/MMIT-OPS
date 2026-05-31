# cPanel Deployment Readiness Notes (MMIT-OPS)

This document captures what this repository expects when deploying to cPanel hosting
(including `ops.midwestmanagedit.com` and `ops-test.midwestmanagedit.com`).

## 1) Required server-only files and secrets

- **Required production OPS config:** `/home/mjrmstlj/private/ops/secrets.php` (not in repo).
- **Required staging OPS config:** `/home/mjrmstlj/private/ops/secrets.staging.php` (not in repo).
- **Deprecated legacy paths (top-level private folder):** `/home/mjrmstlj/private/ops-secrets.php` and `/home/mjrmstlj/private/ops-secrets.staging.php`.
- The app selects config by host:
  - `ops.midwestmanagedit.com` → `/home/mjrmstlj/private/ops/secrets.php`
  - `ops-test.midwestmanagedit.com` → `/home/mjrmstlj/private/ops/secrets.staging.php`
- If the selected file is missing, OPS hard-fails with HTTP 500 (no staging→production fallback).
- Optional explicit override is still supported via `OPS_CONFIG_FILE` (define or environment variable).
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
  - DB host/user/password/dbname in `/home/mjrmstlj/private/ops/secrets.php` (production) or `/home/mjrmstlj/private/ops/secrets.staging.php` (staging)

## 7) Quick preflight checklist

1. Confirm `/home/mjrmstlj/private/ops/secrets.php` exists for production and `/home/mjrmstlj/private/ops/secrets.staging.php` exists for staging.
2. Apply DB schema + all referenced SQL migrations.
3. Verify `storage/onedrive/` exists and is writable by PHP.
4. Verify Stripe webhook endpoint URL points to:
   - `https://ops.midwestmanagedit.com/payments/webhook_stripe.php`
5. Configure cron with account-specific absolute paths.
6. Run smoke checks: login, client list, invoice list, payment page, webhook health page.

## 7.1) Staging safety constants

Keep these constants aligned to environment in server-only config files (constant format via `define(...)` / `ops_define(...)`):

- `APP_ENV`
  - Production: `'production'`
  - Staging: `'staging'`
  - OPS also treats host `ops-test.midwestmanagedit.com` as staging for safety UI detection.
- `MAIL_SANDBOX_ENABLED`
  - `false` in production.
  - `true` in staging when you want all outbound email redirected.
- `MAIL_SANDBOX_TO`
  - Required valid mailbox when `MAIL_SANDBOX_ENABLED=true`.
  - If missing/invalid while sandbox is enabled, OPS fails safe and does not send mail.
- `BASE_URL`
  - Production: `https://ops.midwestmanagedit.com`
  - Staging: `https://ops-test.midwestmanagedit.com`
- `SESSION_NAME`
  - Use distinct values between production and staging to avoid browser cookie collisions across environments.
## 8) cPanel Git deployment layout

- Clone this repository **outside** the live web root, for example:
  - `/home/mjrmstlj/repositories/MMIT-OPS`
- Configure cPanel Git deployment to use this repo checkout as the source.
- Deployment then publishes into the live OPS web root:
  - `/home/mjrmstlj/ops.midwestmanagedit.com`
- The included `.cpanel.yml` deploys tracked source files while preserving server-only/runtime artifacts (for example `inc/config.php` compatibility exclusions, `vendor/`, `storage/`, uploads/log/cache/tmp data, and generated document/log/archive files).


## 9) OPS production vs staging host/docroot mapping

- Production OPS host: `ops.midwestmanagedit.com`
- Staging OPS host: `ops-test.midwestmanagedit.com`
- Production OPS docroot: `/home/mjrmstlj/ops.midwestmanagedit.com`
- Staging OPS docroot: `/home/mjrmstlj/ops-test.midwestmanagedit.com`
- Private config root (must stay outside all web docroots): `/home/mjrmstlj/private`

### Manual staging file creation

1. On the cPanel server, create `/home/mjrmstlj/private/ops/secrets.staging.php` beside `/home/mjrmstlj/private/ops/secrets.php`.
2. Populate staging-only credentials/keys and `BASE_URL=https://ops-test.midwestmanagedit.com`.
3. Do **not** place secrets under either OPS docroot.
4. Smoke test login, MFA/passkeys, Stripe, Syncro, OneDrive, Bold Sign, mail, QR, tracking, accounting, and contracts on staging before production release.
   - Syncro staging safety: OPS LIVE (`ops.midwestmanagedit.com`) may push customer records to Syncro, but OPS TEST (`ops-test.midwestmanagedit.com`) must not push test customers/assets. The central Syncro layer blocks staging `POST`, `PUT`, `PATCH`, and `DELETE` calls with `STAGING_BLOCKED` before any Syncro API request is made.


### Manual migration to organized private OPS config folder

1. On the cPanel server, create the subfolder if it does not exist:
   - `mkdir -p /home/mjrmstlj/private/ops`
2. Copy existing configs into the new organized paths:
   - Production: `/home/mjrmstlj/private/ops/secrets.php`
   - Staging: `/home/mjrmstlj/private/ops/secrets.staging.php`
3. Verify both files remain outside all web docroots (`/home/mjrmstlj/ops.midwestmanagedit.com` and `/home/mjrmstlj/ops-test.midwestmanagedit.com`).
4. Keep staging credentials staging-only; do not reuse production secrets in staging.
5. After verification, remove legacy top-level files when safe.
