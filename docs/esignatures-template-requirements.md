# eSignatures.com template requirements for OPS contracts

OPS sends contracts through the eSignatures.com template API path. It does **not** upload the OPS-generated unsigned draft agreement PDF to the provider during the send flow.

The provider-side template must therefore contain the static legal/service terms from the current OPS agreement packet/order form and must use the merge placeholders below for OPS-specific order-form values. The provider template should **not** include customer-facing `DRAFT` status text.

## OPS send behavior

When an admin sends a contract from the OPS contract workflow, `inc/esignatures.php` builds a JSON payload with:

- `template_id` from `ESIGNATURES_TEMPLATE_ID`.
- One external client signer in `signers[0]` with `name` and `email` from the contract primary contact, falling back to the client email when needed.
- `placeholder_fields[]` entries using `placeholder_key` and `replace_with_text`.
- `metadata` formatted as `contract_id=<OPS contract id>;client_id=<OPS client id>`.
- `custom_webhook_url` when `ESIGNATURES_WEBHOOK_URL` is configured, or the staging default webhook URL is active.
- `test: yes` whenever OPS is in staging/test mode or `ESIGNATURES_TEST_MODE` is enabled.

OPS currently sends one external client signer only. OPS does **not** send a Midwest Managed IT/LnK Consulting countersigner, signer role key, separate sender identity, or signer title in the API payload.

The local PDF generator remains useful for admin review of the unsigned draft packet, but the automated eSignature send relies on the provider-side template and merge placeholders above.


## Live template build steps

Use `docs/esignatures-live-template-source.md` as the copy-ready source for the live/staging eSignatures.com provider-side Managed Services Agreement template. Build the provider template from that source instead of the temporary demo template.

1. In eSignatures.com, create a new provider-side template for the MMIT Managed Services Agreement Packet.
2. Copy the content from `docs/esignatures-live-template-source.md` into the provider template editor.
3. Verify the included Support Policy / SLA and Master Managed Services Agreement terms still match the current OPS `SLA_v2_packet` and `MSA_v2_packet` text from `accounting_contract_default_templates()` in `inc/accounting.php` or the current OPS contract template records if they have been updated administratively.
4. Add one external client signer only. OPS populates that signer from `signers[0].name` and `signers[0].email`. OPS currently does not send a second MMIT/LnK signer, a signer role key, signer title, sender identity, or countersigner metadata.
5. Add the client signing controls for that one signer: a client signature field is required; client printed name, title, date, and an acknowledgment checkbox are recommended when supported by the provider template editor.
6. Add merge placeholders exactly matching the OPS payload names in the placeholder table below. Do not rename, capitalize, space, or prefix/suffix the placeholder keys.
7. Use `{{recurring_total}}` as the main displayed agreement total with the label “Recurring total for selected billing cycle.” Do not use `{{monthly_amount}}` as the primary displayed total because OPS supports Monthly, Quarterly, Semi-annual, and Annual billing cycles.
8. Keep `{{monthly_amount}}` available only as a documented legacy/backward-compatible placeholder for older or temporary templates.
9. Do not include DRAFT, DEMO, “test agreement,” old Agreement Packet PDF / Legal Reference PDF button language, or any production-looking template ID placeholder in the provider template.
10. Save the provider template, then copy the real provider template ID into OPS staging private config only. Do not commit the real provider template ID to the repo.
11. Send a staging test contract, sign as the test signer, and verify that OPS receives the signed/completed webhook and attaches or references the signed copy.

## Merge placeholders

Configure these provider-side merge placeholders exactly as shown.

| Placeholder | Required in provider template? | OPS value / fallback behavior |
| --- | --- | --- |
| `contract_number` | Recommended | OPS contract/order number when available. |
| `contract_title` | Recommended | OPS contract name/title when available. |
| `client_name` | Required / legacy | External signer name from the primary contact. Existing templates depend on this field. |
| `company_name` | Required / legacy | Client DBA name, falling back to legal company name. Existing templates depend on this field. |
| `primary_contact` | Recommended | Same primary contact name used for `client_name`. |
| `contact_email` | Recommended | Primary contact email, falling back to client email. |
| `service_plan` | Required / legacy | Selected service package name when detected, falling back to SLA level or contract name. Existing templates depend on this field. |
| `productivity_platform` | Optional | Selected productivity platform, or `No productivity platform selected`. |
| `license_level` | Optional | Selected license level, or `None selected`. |
| `billing_cycle` | Recommended | Human-readable billing cycle label such as `Monthly`, `Quarterly`, `Semi-annual`, or `Annual`. |
| `recurring_total` | Recommended | Preferred selected billing-cycle recurring total. Use this for the order form total. |
| `covered_workstations` | Optional | Covered workstation/device quantity when available; blank when not populated. |
| `covered_users` | Optional | Covered users/seats quantity when available; blank when not populated. |
| `covered_servers` | Optional | Covered server quantity inferred from server add-ons when available; blank when not populated. |
| `start_date` | Recommended | Contract start date when available. |
| `end_date` | Optional | Contract end date when available; blank can represent month-to-month/no fixed end date depending on template wording. |
| `renewal_terms` | Recommended | Auto-renewal summary, or `No auto-renew`. |
| `service_address` | Optional | Primary service address as multiline text when available. |
| `included_services` | Optional | Included package summary as multiline text when package details are available. |
| `selected_addons` | Optional | Selected add-ons as multiline text when add-ons are selected; blank when none are selected. |
| `monthly_amount` | Optional / legacy fallback | Backward-compatible field for the monthly/base amount. If a monthly/base amount is unavailable, OPS falls back to the recurring total. Existing temporary/demo templates can continue using this field. |

`recurring_total` is the preferred total for the selected billing cycle. Keep `monthly_amount` on legacy or temporary templates only when needed for backward compatibility.

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
   - OPS does not currently send a named signer role key, signer title, sender identity, or Midwest Managed IT countersigner in the API payload.
2. Add the signing fields required by the provider template for that signer.
   - Required: client signature field.
   - Recommended: client printed name, title, and date fields if the template supports capturing those signer-entered values.
   - Do not require a Midwest Managed IT/LnK Consulting countersignature field unless it is handled entirely provider-side outside the current OPS send payload.
3. Add merge placeholders matching the names in the merge placeholder table above.
4. Keep static legal/service terms in the provider template aligned with the current OPS agreement packet/order form.
5. Do not include customer-facing `DRAFT` status text in the provider-side template.
6. Configure the webhook/callback URL for completion events.
   - Staging/test: `https://ops-test.midwestmanagedit.com/webhooks/esignatures.php`.
   - Production: set the production URL explicitly in private config as `ESIGNATURES_WEBHOOK_URL` after provider verification.
7. If the provider template supports post-signature redirect/return URLs, configure them provider-side only if desired. OPS does not send a redirect/return URL in the current API payload.
8. Verify completed webhook payloads expose a provider contract/document ID, signed/completed status, and either a signed PDF URL/reference or contract details endpoint from which OPS can retrieve it.

## Provider values that are intentionally not hardcoded

- No real `ESIGNATURES_API_TOKEN` belongs in the repo.
- No fake or unverified `ESIGNATURES_TEMPLATE_ID` should be committed.
- No sender identity, return URL, countersigner role, or Midwest Managed IT countersigner is hardcoded in OPS because the current send payload does not include those fields.
