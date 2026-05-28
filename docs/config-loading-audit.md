# Config loading audit and migration plan

## Scope
Audit-only pass for config loading in this repository (`/workspace/MMIT-OPS`). No runtime behavior or secret files changed.

## Current config paths referenced by code

### Active OPS paths in bootstrap
- Primary production path: `/home/mjrmstlj/private/ops/secrets.php`
- Primary staging path (host `ops-test.midwestmanagedit.com`): `/home/mjrmstlj/private/ops/secrets.staging.php`
- Legacy production fallback (only if primary production file is missing): `/home/mjrmstlj/private/ops-secrets.php`
- Optional full override via constant/env: `OPS_CONFIG_FILE`

All of the above are selected in `inc/bootstrap.php` before `require_once $cfg`.

### Other app paths (MMIT/scheduler/portal)
No references were found in this repository to:
- `/home/mjrmstlj/private/mmit-secrets.php`
- `/home/mjrmstlj/private/mmit-scheduler.php`
- `/home/mjrmstlj/private/mmit/secrets.php`
- `/home/mjrmstlj/private/mmit/scheduler.php`
- `/home/mjrmstlj/private/portal/secrets.php`
- `/home/mjrmstlj/private/portal/secrets.staging.php`

## Expected config format in this repository

### OPS app format
This repo expects **constant definitions** (via `define(...)`) in the loaded private file.

Evidence:
- DB bootstrap uses constants (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`) directly.
- Mail and payment systems read named constants dynamically with `defined(...)`/`constant(...)` helpers.
- Sample file is constant-based (`inc/config.sample.php`).

### Array-returning config
`inc/config.example.php` is array-returning (`return [ ... ]`) and is not used by `inc/bootstrap.php` path loading. It appears legacy/example-only for this repo’s current runtime.

## Required constants/keys by subsystem (from code usage)

### Bootstrap/session/security
- `APP_NAME`, `APP_ENV`, `BASE_URL`, `APP_TIMEZONE`
- `SESSION_NAME`, `SESSION_TTL_SECONDS`, `SESSION_ABS_TTL_SECONDS`, `SESSION_REGEN_SECONDS`, `SESSION_BIND_UA_IP`, `SESSION_COOKIE_DOMAIN`
- `COOKIE_SECURE`, `COOKIE_HTTPONLY`, `COOKIE_SAMESITE`
- `APP_ENC_KEY_B64`
- Optional loader override: `OPS_CONFIG_FILE`

### Database
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`

### Accounting and recurring scheduler
- `ACCOUNTING_ENABLED`
- Scheduler entrypoint `cron/generate_recurring_invoices.php` depends on normal bootstrap config and accounting readiness.

### Email integration
Mail module reads constants (with env fallback) for:
- Transport/control: `MAIL_TRANSPORT_PRIMARY`, `MAIL_TRANSPORT_FALLBACK`, `MAIL_HTTP_TIMEOUT`, `MAIL_DEBUG`, `MAIL_LOG_FILE`, `MAIL_ALLOW_NATIVE_FALLBACK`, `MAIL_GRAPH_SAVE_TO_SENT_ITEMS`, `MAIL_SANDBOX_ENABLED`, `MAIL_SANDBOX_TO`
- Sender identities: `MAIL_SENDER_*`
- Graph: `MAIL_GRAPH_TENANT_ID`, `MAIL_GRAPH_CLIENT_ID`, `MAIL_GRAPH_CLIENT_SECRET`
- SMTP: `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT`, `MAIL_SMTP_ENCRYPTION`, `MAIL_SMTP_TIMEOUT`, `MAIL_SMTP_EHLO`, `MAIL_SMTP_*_USERNAME`, `MAIL_SMTP_*_PASSWORD`, `MAIL_SMTP_USERNAME`, `MAIL_SMTP_PASSWORD`

### Payment integrations
- `PAYMENT_GATEWAY_DEFAULT`
- `PAYMENT_DEFAULT_DEPOSIT_ACCOUNT_CODE`, `PAYMENT_DEFAULT_FEE_EXPENSE_ACCOUNT_CODE`
- Stripe: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CHECKOUT_SUCCESS_URL`, `STRIPE_CHECKOUT_CANCEL_URL`

### Other integrations used by this repo
- OneDrive: `ONEDRIVE_CLIENT_ID`, `ONEDRIVE_CLIENT_SECRET`, `ONEDRIVE_REDIRECT_URI`, `ONEDRIVE_TENANT_ID`, `ONEDRIVE_RECEIPTS_ROOT`
- QBO: `QBO_CLIENT_ID`, `QBO_CLIENT_SECRET`, `QBO_REDIRECT_URI`, `QBO_ENV`, `QBO_AUTOPUSH_ON_PACKED`, `QBO_MINORVERSION`
- Syncro: `SYNCRO_SUBDOMAIN`, `SYNCRO_API_KEY`, `SYNCRO_BASE_URL`

## Proposed final config paths
(As requested target structure.)

- `/home/mjrmstlj/private/mmit/secrets.php`
- `/home/mjrmstlj/private/mmit/secrets.staging.php`
- `/home/mjrmstlj/private/mmit/scheduler.php`
- `/home/mjrmstlj/private/mmit/scheduler.staging.php`
- `/home/mjrmstlj/private/ops/secrets.php`
- `/home/mjrmstlj/private/ops/secrets.staging.php`
- `/home/mjrmstlj/private/portal/secrets.php`
- `/home/mjrmstlj/private/portal/secrets.staging.php`

## Manual copy commands (no behavior changes)

> Run on host as a privileged shell user. These commands copy only; they do not delete legacy files.

```bash
mkdir -p /home/mjrmstlj/private/mmit /home/mjrmstlj/private/ops /home/mjrmstlj/private/portal

# MMIT
cp -a /home/mjrmstlj/private/mmit-secrets.php /home/mjrmstlj/private/mmit/secrets.php
cp -a /home/mjrmstlj/private/mmit-scheduler.php /home/mjrmstlj/private/mmit/scheduler.php
# create staging variants from known-good source, then edit values manually as needed
cp -an /home/mjrmstlj/private/mmit/secrets.php /home/mjrmstlj/private/mmit/secrets.staging.php
cp -an /home/mjrmstlj/private/mmit/scheduler.php /home/mjrmstlj/private/mmit/scheduler.staging.php

# OPS
cp -a /home/mjrmstlj/private/ops-secrets.php /home/mjrmstlj/private/ops/secrets.php
cp -a /home/mjrmstlj/private/ops-secrets.staging.php /home/mjrmstlj/private/ops/secrets.staging.php

# Portal (if legacy files exist elsewhere, map/copy accordingly)
cp -an /home/mjrmstlj/private/portal/secrets.php /home/mjrmstlj/private/portal/secrets.staging.php
```

## Stale/legacy paths to deprecate later
- `/home/mjrmstlj/private/ops-secrets.php` (explicit fallback in code).
- Potentially `/home/mjrmstlj/private/mmit-secrets.php` and `/home/mjrmstlj/private/mmit-scheduler.php` in other repos once references are removed there.

## Risks
- Staging/prod cross-contamination if wrong file copied into `*.staging.php`.
- Inconsistent config format between apps (constants vs returned arrays) can break bootstrap if loader assumptions differ.
- Hidden references in other repos (MMIT/portal/scheduler) may still point to flat legacy filenames.
- Missing optional constants can silently degrade features (mail transport fallback, payment links, OneDrive/QBO auth).

## Exact next implementation steps (no changes applied in this audit)
1. Verify private files exist on host and compare content fingerprints (hash only, no secret output).
2. In each app repo (MMIT, OPS, Portal), inventory current config loader paths and format expectations.
3. Add temporary dual-path loading in each app where needed (new path first, legacy fallback second), with strict no-cross-env fallback.
4. Deploy and validate app health for prod and staging.
5. After validation window, remove legacy flat-file fallbacks and update runbooks.
6. Keep rollback instructions: restore previous private filenames/symlinks immediately if bootstraps fail.
