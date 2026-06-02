# eSignatures.com template requirements for OPS contracts

OPS currently sends contracts through the eSignatures.com template API path. It does **not** upload the OPS-generated draft agreement PDF to the provider during the send flow.

## OPS send behavior

When an admin sends a contract from the OPS contract workflow, `inc/esignatures.php` builds a JSON payload with:

- `template_id` from `ESIGNATURES_TEMPLATE_ID`.
- One signer in `signers[0]` with `name` and `email` from the contract primary contact, falling back to the client email when needed.
- `placeholder_fields[]` entries using `placeholder_key` and `replace_with_text`.
- `metadata` formatted as `contract_id=<OPS contract id>;client_id=<OPS client id>`.
- `custom_webhook_url` when `ESIGNATURES_WEBHOOK_URL` is configured, or the staging default webhook URL is active.
- `test: yes` whenever OPS is in staging/test mode or `ESIGNATURES_TEST_MODE` is enabled.

The local PDF generator remains useful for admin review of the unsigned draft packet, but the automated eSignature send relies on the provider-side template and merge placeholders above.

## Required OPS configuration values

Set these in the private OPS config for the target environment; do not commit real secrets or unknown placeholder IDs.

| Constant | Required | Purpose |
| --- | --- | --- |
| `ESIGNATURES_ENABLED` | Yes | Must be true to expose the eSignatures send action. |
| `ESIGNATURES_API_TOKEN` | Yes | Provider API token used as the `token` query parameter for API calls. |
| `ESIGNATURES_TEMPLATE_ID` | Yes | Provider-side template ID for the OPS managed services agreement template. |
| `ESIGNATURES_BASE_URL` | Yes | Defaults to `https://esignatures.com/api`; override only if the provider endpoint changes. |
| `ESIGNATURES_WEBHOOK_URL` | Yes for production; optional in staging | Completion webhook target. Staging defaults to `https://ops-test.midwestmanagedit.com/webhooks/esignatures.php` when blank. |
| `ESIGNATURES_TEST_MODE` | Environment-specific | Forces provider test sends outside the staging host. |
| `ESIGNATURES_LIVE_CONFIRMED` | Production only | Must be true before non-staging live sends are allowed. |

## Provider-side template setup checklist

Create or verify one eSignatures.com template whose ID is configured as `ESIGNATURES_TEMPLATE_ID`.

1. Add exactly one external signer recipient for the client signer.
   - OPS sends the signer as `signers[0].name`.
   - OPS sends the signer as `signers[0].email`.
   - OPS does not currently send a named signer role key, signer title, or separate sender identity in the API payload.
2. Add the signing fields required by the provider template for that signer.
   - Required: client signature field.
   - Recommended: client printed name, title, and date fields if the template needs those values captured by the signer.
   - If the provider requires sender/company countersignature fields, configure them in the provider template; OPS does not populate a second signer in the send payload today.
3. Add these merge placeholders exactly as OPS sends them:
   - `client_name`
   - `company_name`
   - `service_plan`
   - `monthly_amount`
4. Configure the webhook/callback URL for completion events.
   - Staging/test: `https://ops-test.midwestmanagedit.com/webhooks/esignatures.php`.
   - Production: set the production URL explicitly in private config as `ESIGNATURES_WEBHOOK_URL` after provider verification.
5. If the provider template supports post-signature redirect/return URLs, configure them provider-side only if desired. OPS does not send a redirect/return URL in the current API payload.
6. Verify completed webhook payloads expose a provider contract/document ID, signed/completed status, and either a signed PDF URL/reference or contract details endpoint from which OPS can retrieve it.

## Provider values that are intentionally not hardcoded

- No real `ESIGNATURES_API_TOKEN` belongs in the repo.
- No fake or unverified `ESIGNATURES_TEMPLATE_ID` should be committed.
- No sender identity, return URL, or countersigner role is hardcoded in OPS because the current send payload does not include those fields.
