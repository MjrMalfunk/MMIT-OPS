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
- `PATCH /api/v1/work-orders/:id/client` explicitly links or unlinks a client.
- `PATCH /api/v1/work-orders/:id/status` applies a valid lifecycle transition.

Client and work-order endpoints require an authenticated V2 OPS session before
they can be read or changed.

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
