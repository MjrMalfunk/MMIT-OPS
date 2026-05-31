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
define('STRIPE_WEBHOOK_SECRET', '');
// Stripe webhook endpoint: BASE_URL . '/payments/webhook_stripe.php'
define('STRIPE_CHECKOUT_SUCCESS_URL', BASE_URL . '/payments/return.php?gateway=stripe&session_id={CHECKOUT_SESSION_ID}');
define('STRIPE_CHECKOUT_CANCEL_URL', BASE_URL . '/payments/pay.php?cancelled=1');


// eSignatures.com integration
// Store the real API token only in private config. OPS TEST/staging forces test mode
// and sends payloads with "test":"yes"; live stays disabled unless explicitly enabled.
define('ESIGNATURES_ENABLED', false);
define('ESIGNATURES_API_TOKEN', '');
define('ESIGNATURES_TEMPLATE_ID', '20086199-7b34-44a3-b0bd-08010540cda2');
define('ESIGNATURES_TEST_MODE', false);
define('ESIGNATURES_BASE_URL', 'https://esignatures.com/api');

// Syncro integration
// OPS LIVE (APP_ENV=production / ops.midwestmanagedit.com) may push customer records to Syncro.
// OPS TEST/staging (APP_ENV=staging or ops-test.midwestmanagedit.com) must not push to Syncro;
// inc/syncro.php blocks POST, PUT, PATCH, and DELETE centrally before any external API call.
define('SYNCRO_SUBDOMAIN', 'midwestmanagedit');
define('SYNCRO_API_KEY', '');
define('SYNCRO_BASE_URL', ''); // Optional override. Leave blank to use https://<subdomain>.syncromsp.com/api/v1/

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
