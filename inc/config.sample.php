<?php
declare(strict_types=1);

// MITT Ops local/site configuration.
// Fill in the database user/password for your hosting account.

define('APP_ENV', 'production');
define('APP_NAME', 'MITT Ops');
define('BASE_URL', 'https://ops.midwestmanagedit.com');

define('SESSION_NAME', 'mittops');
define('SESSION_TTL_SECONDS', 600);
define('SESSION_ABS_TTL_SECONDS', 43200);
define('SESSION_REGEN_SECONDS', 900);
define('SESSION_BIND_UA_IP', false);
define('SESSION_COOKIE_DOMAIN', '.midwestmanagedit.com');

define('DB_HOST', 'localhost');
define('DB_NAME', 'mjrnstlj_mittops');
define('DB_USER', 'REPLACE_WITH_DB_USER');
define('DB_PASS', 'REPLACE_WITH_DB_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

define('MFA_TOTP_ISSUER', 'MITT Ops');
define('MFA_TOTP_PERIOD', 30);
define('MFA_TOTP_DIGITS', 6);

define('COOKIE_SECURE', true);
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Lax');
define('TRUST_X_FORWARDED_PROTO', false);
define('APP_TIMEZONE', 'America/Indiana/Indianapolis');

define('ACCOUNTING_ENABLED', true);

// Generate a new 32-byte encryption key before production use.
define('APP_ENC_KEY_B64', 'REPLACE_WITH_BASE64_32_BYTE_KEY');

// Optional integrations - leave blank until configured.
define('ONEDRIVE_CLIENT_ID', '');
define('ONEDRIVE_CLIENT_SECRET', '');
define('ONEDRIVE_REDIRECT_URI', BASE_URL . '/accounting/onedrive_callback.php');
define('ONEDRIVE_TENANT_ID', ''); // optional: set to your Entra Directory (tenant) ID to avoid wrong-tenant logins
define('ONEDRIVE_RECEIPTS_ROOT', 'MITT Receipts');

define('QBO_CLIENT_ID', '');
define('QBO_CLIENT_SECRET', '');
define('QBO_REDIRECT_URI', BASE_URL . '/accounting/qbo_callback.php');
define('QBO_ENV', 'production');
define('QBO_AUTOPUSH_ON_PACKED', false);
define('QBO_MINORVERSION', 65);

define('GL_DEFAULT_CASH_ACCT_NO', '1000');
define('GL_DEFAULT_CARD_ACCT_NO', '2100');

// Payment gateway configuration
define('PAYMENT_GATEWAY_DEFAULT', 'STRIPE');
define('PAYMENT_DEFAULT_DEPOSIT_ACCOUNT_CODE', '1000');
define('PAYMENT_DEFAULT_FEE_EXPENSE_ACCOUNT_CODE', '5070');

define('STRIPE_SECRET_KEY', '');
define('STRIPE_PUBLISHABLE_KEY', '');
define('STRIPE_WEBHOOK_SECRET', ''); // Fallback signing secret if mode-specific secrets are not set.
define('STRIPE_TEST_WEBHOOK_SECRET', ''); // Use with sk_test_* / sandbox webhooks.
define('STRIPE_LIVE_WEBHOOK_SECRET', ''); // Use with sk_live_* / production webhooks.
// Stripe webhook endpoint: BASE_URL . '/payments/webhook_stripe.php'
define('STRIPE_CHECKOUT_SUCCESS_URL', BASE_URL . '/payments/return.php?gateway=stripe&session_id={CHECKOUT_SESSION_ID}');
define('STRIPE_CHECKOUT_CANCEL_URL', BASE_URL . '/payments/pay.php?cancelled=1');


// eSignatures.com integration
// Store the real API token only in private config. OPS TEST/staging forces test mode
// and sends payloads with "test":"yes"; live stays disabled unless explicitly enabled.
define('ESIGNATURES_ENABLED', false);
define('ESIGNATURES_API_TOKEN', '');
define('ESIGNATURES_TEMPLATE_ID', '');
define('ESIGNATURES_TEST_MODE', false);
define('ESIGNATURES_BASE_URL', 'https://esignatures.com/api');
// Staging/test should point eSignatures contract completion events at OPS TEST.
// Leave blank in live unless the production eSignatures webhook has been registered and verified.
define('ESIGNATURES_WEBHOOK_URL', '');

// Syncro integration
// OPS LIVE (APP_ENV=production / ops.midwestmanagedit.com) may push customer records to Syncro.
// OPS TEST/staging (APP_ENV=staging or ops-test.midwestmanagedit.com) blocks Syncro writes by default.
// Controlled staging/testing override only: set true in a private local inc/config.php to allow
// Syncro POST/PUT/PATCH calls from OPS staging. DELETE remains blocked even when true.
// Never enable in committed config or without an active manual test window.
define('SYNCRO_ALLOW_STAGING_WRITES', false);
define('SYNCRO_SUBDOMAIN', 'midwestmanagedit');
define('SYNCRO_API_KEY', '');
define('SYNCRO_BASE_URL', ''); // Optional override. Leave blank to use https://<subdomain>.syncromsp.com/api/v1/
// Required when Syncro GET /settings omits dropdown options for MMIT Service Tier.
// Populate from the admin Syncro custom-field metadata diagnostic or a live asset custom_fields dump.
// OPS fails closed instead of sending raw Manage/Protect/Govern labels to this dropdown if these IDs cannot be resolved.
// define('MMIT_SYNCRO_SERVICE_TIER_OPTION_IDS', '{"Manage":"REPLACE_WITH_MANAGE_OPTION_ID","Protect":"REPLACE_WITH_PROTECT_OPTION_ID","Govern":"REPLACE_WITH_GOVERN_OPTION_ID"}');
// define('MMIT_SYNCRO_SERVICE_TIER_OPTION_ID_MANAGE', '');
// define('MMIT_SYNCRO_SERVICE_TIER_OPTION_ID_PROTECT', '');
// define('MMIT_SYNCRO_SERVICE_TIER_OPTION_ID_GOVERN', '');
define('SYNCRO_POLICY_ASSIGNMENTS', [
    // Configure real Syncro policy IDs only; leave null to fail closed with PENDING_MANUAL.
    // In staging, positive numeric IDs are accepted only when explicitly listed here.
    // If staging policy values are strings, they must refer to MMIT-Test-* policies.
    'manage.standard.root' => null,
    'manage.deploy.workstations' => null,
    'manage.deploy.servers' => null,
    'manage.production.workstations' => null,
    'manage.production.servers' => null,
    'protect.standard.root' => null,
    'protect.deploy.workstations' => null,
    'protect.deploy.servers' => null,
    'protect.production.workstations' => null,
    'protect.production.servers' => null,
    'govern.standard.root' => null,
    'govern.deploy.workstations' => null,
    'govern.deploy.servers' => null,
    'govern.production.workstations' => null,
    'govern.production.servers' => null,
]);

// Email delivery (Microsoft 365 / Exchange Online)
define('MAIL_TRANSPORT_PRIMARY', 'smtp');
define('MAIL_TRANSPORT_FALLBACK', 'graph');
define('MAIL_HTTP_TIMEOUT', 20);
define('MAIL_DEBUG', false);
define('MAIL_LOG_FILE', dirname(__DIR__) . '/ops-mail.log');
define('MAIL_ALLOW_NATIVE_FALLBACK', false);
define('MAIL_GRAPH_SAVE_TO_SENT_ITEMS', true);

define('MAIL_SENDER_BILLING_NAME', 'Midwest Managed IT Billing');
define('MAIL_SENDER_BILLING_EMAIL', 'billing@midwestmanagedit.com');
define('MAIL_SENDER_BILLING_REPLY_TO_NAME', 'Midwest Managed IT Billing');
define('MAIL_SENDER_BILLING_REPLY_TO_EMAIL', 'billing@midwestmanagedit.com');

define('MAIL_SENDER_SUPPORT_NAME', 'Midwest Managed IT Support');
define('MAIL_SENDER_SUPPORT_EMAIL', 'support@midwestmanagedit.com');
define('MAIL_SENDER_SUPPORT_REPLY_TO_NAME', 'Midwest Managed IT Support');
define('MAIL_SENDER_SUPPORT_REPLY_TO_EMAIL', 'support@midwestmanagedit.com');

define('MAIL_SENDER_NOREPLY_NAME', 'No Reply');
define('MAIL_SENDER_NOREPLY_EMAIL', 'noreply@midwestmanagedit.com');
define('MAIL_SENDER_NOREPLY_REPLY_TO_NAME', 'Midwest Managed IT Support');
define('MAIL_SENDER_NOREPLY_REPLY_TO_EMAIL', 'support@midwestmanagedit.com');

define('MAIL_SENDER_DEFAULT_NAME', 'Midwest Managed IT');
define('MAIL_SENDER_DEFAULT_EMAIL', 'billing@midwestmanagedit.com');
define('MAIL_SENDER_DEFAULT_REPLY_TO_NAME', 'Midwest Managed IT');
define('MAIL_SENDER_DEFAULT_REPLY_TO_EMAIL', 'billing@midwestmanagedit.com');

// Sandbox redirect. Leave OFF in production unless you intentionally want all mail redirected.
define('MAIL_SANDBOX_ENABLED', false);
define('MAIL_SANDBOX_TO', ''); // Required valid email when MAIL_SANDBOX_ENABLED=true, otherwise mail send will fail safe.

// Microsoft Graph app-only mail. Reuse the same app registration used by the portal mailer.
define('MAIL_GRAPH_TENANT_ID', ONEDRIVE_TENANT_ID);
define('MAIL_GRAPH_CLIENT_ID', '');
define('MAIL_GRAPH_CLIENT_SECRET', '');

// SMTP fallback (Microsoft 365 authenticated submission).
define('MAIL_SMTP_HOST', 'smtp.office365.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_ENCRYPTION', 'tls');
define('MAIL_SMTP_TIMEOUT', 20);
define('MAIL_SMTP_EHLO', 'ops.midwestmanagedit.com');

// Per-channel SMTP credentials are optional. When blank, the generic username/password are used.
define('MAIL_SMTP_BILLING_USERNAME', 'billing@midwestmanagedit.com');
define('MAIL_SMTP_BILLING_PASSWORD', '');
define('MAIL_SMTP_SUPPORT_USERNAME', 'support@midwestmanagedit.com');
define('MAIL_SMTP_SUPPORT_PASSWORD', '');
define('MAIL_SMTP_NOREPLY_USERNAME', 'noreply@midwestmanagedit.com');
define('MAIL_SMTP_NOREPLY_PASSWORD', '');
define('MAIL_SMTP_USERNAME', '');
define('MAIL_SMTP_PASSWORD', '');

/*
|--------------------------------------------------------------------------
| N-able Cove Data Protection JSON-RPC API
|--------------------------------------------------------------------------
| Store real values only in /home/mjrmstlj/private/ops/secrets*.php.
| Do not commit live credentials.
*/
define('COVE_JSONRPC_URL', '');
define('COVE_PARTNER_NAME', '');
define('COVE_USERNAME', '');
define('COVE_PASSWORD', '');
define('COVE_DEFAULT_PARTNER_ID', 0);

/*
|--------------------------------------------------------------------------
| Huntress REST API
|--------------------------------------------------------------------------
| Store real API credentials only in private config/vault.
*/
define('HUNTRESS_API_BASE_URL', 'https://api.huntress.io/v1');
define('HUNTRESS_API_KEY', '');
define('HUNTRESS_API_SECRET', '');

/*
|--------------------------------------------------------------------------
| ScoutDNS Operator API
|--------------------------------------------------------------------------
| Store real API tokens only in private config/vault.
*/
define('SCOUTDNS_API_BASE_URL', 'https://api.scoutdns.com/v1');
define('SCOUTDNS_API_TOKEN', '');
