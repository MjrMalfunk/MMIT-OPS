# MMIT OPS v2 backend

The starter backend is an Express + TypeScript API with Prisma wired for MySQL
and Redis available through Docker Compose.

## First run

1. Copy `.env.example` to `.env` and replace `change-this-before-running` with
   one strong MySQL root password in both places.
2. Build and start the stack with `docker compose up --build -d`.
3. Confirm the API with `curl http://127.0.0.1:3000/api/health`.

The first V2 vertical slice is Field Work Orders. Compose applies Prisma
migrations after MySQL is healthy, then starts the API. The database and API
are bound to localhost only; put a reverse proxy and authentication in front of
the API before exposing it beyond the server.

## Field Work Order API

- `GET /api/health` checks the API and MySQL connection.
- `GET /api/v1/clients` lists client profiles. Optional: `?search=term`.
- `POST /api/v1/clients` creates a client profile, with an optional stable
  `syncroCustomerId` and Manage/Protect/Govern service tier.
- `GET /api/v1/clients/:id` returns a client and its recent work orders.
- `PATCH /api/v1/clients/:id` updates only supplied profile fields; clients are
  never deleted by this API slice.
- `GET /api/v1/work-orders` lists work orders. Optional: `?status=SCHEDULED`.
- `POST /api/v1/work-orders` creates a work order with an explicit source and
  source reference. The source/reference pair is unique, so an importer cannot
  silently duplicate a job.
- `GET /api/v1/work-orders/:id` returns one work order.
- `PATCH /api/v1/work-orders/:id` updates selected operational values: title,
  schedule, actual check-in/out, gross pay, mileage, drive/onsite/admin minutes,
  and notes. Check-out cannot precede check-in. Invoiced and paid work orders
  allow notes only until a dedicated adjustment workflow is added.
- `PATCH /api/v1/work-orders/:id/pay` updates the compensation terms before a
  work order is invoiced or paid. `payType` is `FIXED`, `HOURLY`, or `BLENDED`.
  `grossPay` is the advertised/max amount; the separate `actualGrossPay` is
  calculated when the work order moves to `COMPLETED`.
- `PATCH /api/v1/work-orders/:id/client` explicitly links or unlinks a client.
- `PATCH /api/v1/work-orders/:id/status` applies a valid lifecycle transition.
- `GET /api/v1/work-orders/:id/costs` returns active materials, expenses, and
  separate cost/bill totals for the work order.
- `POST /api/v1/work-orders/:id/expenses` records an operating expense with an
  actual cost and optional client bill amount. Categories are material, travel,
  parking/toll, equipment rental, and other.
- `POST /api/v1/work-orders/:id/materials` records a purchased item or an
  inventory pull with quantity, unit cost, and optional unit price.
- `DELETE /api/v1/work-orders/:id/expenses/:expenseId` and
  `DELETE /api/v1/work-orders/:id/materials/:materialId` are OWNER/ADMIN-only
  soft-void actions. They retain the entry and its audit trail rather than
  deleting financial history.
- `GET /api/v1/work-orders/:id/invoice-drafts` returns historical V2 invoice
  snapshots for one work order.
- `POST /api/v1/work-orders/:id/invoice-drafts` creates a fixed invoice draft
  from completed direct work: billable labor, materials with a unit price, and
  expenses with a bill amount. FieldNation work orders remain payout-only.
- `POST /api/v1/work-orders/:id/invoice-drafts/:invoiceId/issue` is
  OWNER/ADMIN-only. It issues a draft and moves the completed work order to
  INVOICED in the same audited transaction. It also creates one balanced V2
  accounting journal: debit `1120` Accounts Receivable and credit `4100` Field
  Service Income. It does not send an email, charge a card, or touch V1.
- `POST /api/v1/work-orders/:id/invoice-drafts/:invoiceId/void` is
  OWNER/ADMIN-only and only voids an unissued draft, preserving its snapshot.
- `GET /api/v1/work-orders/:id/attachments` lists active private attachments.
- `POST /api/v1/work-orders/:id/attachments` accepts one binary file (up to
  25 MiB) from OWNER, ADMIN, or OPERATOR. The client sends an
  `application/octet-stream` body plus `X-File-Name`, `X-Attachment-Kind`, and
  optional `X-Original-Content-Type` headers.
- `GET /api/v1/work-orders/:id/attachments/:attachmentId/download` streams an
  attachment only to an authenticated OPS user.
- `DELETE /api/v1/work-orders/:id/attachments/:attachmentId` is OWNER/ADMIN
  only and soft-deletes the record; the private file is retained for audit.
- `GET /api/v1/audit-events` is OWNER/ADMIN-only and returns the newest 50
  audit events (up to 100). Optional filters: `subjectType`, `subjectId`.
- `GET /api/v1/accounting/journals` is OWNER/ADMIN-only and returns newest V2
  journals (up to 100), including their immutable debit and credit lines.

Client and work-order endpoints require an authenticated V2 OPS session before
they can be read or changed.

Every authenticated client create/update, work-order create/client-link/status
change, and OPS invitation is appended to the V2 audit ledger in the same
database transaction as the change. Audit records are read-only through this
API. They retain the acting OPS identity and safe before/after operational
snapshots; free-form notes are represented only as present/absent, never copied
into the audit payload.

Attachment binaries live in the Docker-managed `attachment_data` volume, not
inside a public document root. Every upload and soft-delete is appended to the
same V2 audit ledger. There is intentionally no direct public attachment URL.

Materials and expenses are captured independently from attachments so a
receipt can be uploaded privately without exposing it or mixing binary data
into a financial record. Their cost and optional bill totals remain editable
only before the work order is invoiced or paid. V2 does not post accounting
journals in this slice; the next accounting layer will consume these audited
source records without duplicating V1 accounting data.

Invoice drafts are V2-only financial snapshots for direct/client work. They
never exist for FieldNation jobs, because those jobs are provider payouts rather
than customer receivables. Issuing a draft makes the source work order
historical and prepares it for a later payment/reconciliation module; it does
not represent payment, a bank deposit, Stripe processing, or an external invoice
delivery.

The journal source pair is unique, so an invoice cannot create duplicate revenue
on retry. A journal is created atomically with invoice issue, is verified as
positive and balanced before commit, and locks the source work order's financial
values. Payment clearing and adjustment journals are later modules; they will
add entries rather than alter these posted revenue lines.

## Payment reconciliation

- `POST /api/v1/invoices/:invoiceId/payments` records an expected payment as
  `PENDING`. ACH/bank is the default operational choice; card is an explicit
  fallback. A pending ACH submission is never treated as paid.
- `POST /api/v1/payments/:paymentId/reconcile` is OWNER/ADMIN-only and is the
  only route that clears a payment. It requires the confirmed processor event,
  balance-transaction identifier, actual method, gross, fee, net, and settled
  time. The optional payout reference is retained when available.
- An exact, full settlement posts one balanced V2 journal: debit `1010` Bank
  for net cash, debit `5200` Processing Fees when applicable, and credit `1120`
  Accounts Receivable for gross. It then marks the invoice and work order paid.
- A method, reference, gross, or partial-payment mismatch is recorded as
  `REVIEW_REQUIRED`; it creates no journal and changes neither invoice nor work
  order status. Replaying a processor event returns the existing reconciliation
  rather than duplicating accounting.
- `POST /api/v1/payments/:paymentId/post` is deliberately disabled. Future
  Stripe/webhook integration must call the reconciliation contract with actual
  settlement values; this patch adds no Stripe credentials or external calls.
- `GET /api/v1/payment-reconciliations` is an OWNER/ADMIN-only review queue;
  it defaults to `REVIEW_REQUIRED` and accepts `status` and `limit` filters.
- `POST /api/v1/payment-reconciliations/:eventId/approve` rechecks that the
  settlement is still a complete invoice payment before posting it. Approval
  is audited and cannot override a stale, partial, or conflicting settlement.
- `POST /api/v1/payment-reconciliations/:eventId/reject` requires a note and
  closes the event without changing payment, invoice, work-order, or journal
  state.

## FieldNation intake foundation

- `POST /api/v1/fieldnation/imports` accepts one raw FieldNation message at a
  time. It is duplicate-safe on `messageId`, preserves the original text, and
  stores the first parsed opportunity fields plus an explainable score.
- `GET /api/v1/fieldnation/imports` lists the newest captured messages. Use
  `?status=REVIEW_REQUIRED` to show messages that need operator attention.
- `GET /api/v1/fieldnation/imports/:id` returns the parsed import without
  exposing the raw message in list responses.
- `POST /api/v1/fieldnation/imports/:id/convert` creates a V2
  `FIELD_NATION` work order only after a source reference is present. Conversion
  is explicit and audited; email capture never silently creates a work order.

This is the safe first importer slice. Mailbox polling, the complete V1
FieldNation parser, and automatic scoring refinements will be layered on after
real redacted messages have been replayed through this review queue.

## Pay terms and completion payout

V2 keeps the original advertised amount in `grossPay` and preserves the terms
used to derive it. Fixed work uses `payBaseAmount`. Hourly work uses
`payHourlyRate`, optionally capped by `payHoursCap`. Blended work uses
`payBaseAmount` for `payBaseHours`, then pays `payHourlyRate` for additional
onsite hours up to `payHoursCap`.

For example, `$100 for 2 hours, up to 3 additional hours at $40/hour` is stored
with an advertised maximum of `$220`. If the completed work has four onsite
hours, V2 calculates an actual payout of `$180` (`$100 + 2 × $40`) and retains
the formula and inputs in `payCalculation` for auditability. Onsite minutes are
preferred; when they are not recorded, check-in and check-out timestamps are
used. Variable-pay work cannot be completed until one of those duration sources
is available.

## OPS identity and access

V2 uses password sign-in plus mandatory TOTP and one-time recovery codes. It
does not use Portal-style email PIN or passwordless sign-in. Passkeys are a
later enhancement, available only after MFA is established.

Before the first identity deployment, generate three different secrets and put
them only in the local `.env` file. Never commit them:

```bash
openssl rand -base64 32
```

- `POST /api/v1/auth/bootstrap` creates the one initial OWNER only while no
  V2 OPS users exist. It requires `V2_BOOTSTRAP_TOKEN` and returns a TOTP setup
  secret/URI.
- `POST /api/v1/auth/login` requires email, password, and a TOTP code (or an
  unused recovery code). It returns a 12-hour bearer token.
- `POST /api/v1/auth/invitations` is OWNER/ADMIN-only. Invitations are
  single-use and expire after 24 hours.
- `POST /api/v1/auth/invitations/accept` creates the invited user in pending
  MFA state and returns their TOTP setup information.
- `GET /api/v1/auth/me` and all client/work-order routes require the bearer
  token. Viewer users may read; OWNER, ADMIN, and OPERATOR may write.

## Local commands

- `pnpm dev` - run the API with source watching.
- `pnpm typecheck` - check TypeScript without emitting files.
- `pnpm build` - compile to `dist/`.
- `pnpm prisma:generate` - regenerate the Prisma client.
- `pnpm prisma:migrate:deploy` - apply checked-in migrations.

`pnpm-workspace.yaml` records the reviewed dependency build scripts required
by this project. Keep that allow-list explicit; do not switch to an allow-all
policy.
