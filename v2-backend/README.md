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

The endpoint layer deliberately has no public authentication yet. That is the
next safety layer, before this API is connected to a browser UI or an importer.

## Local commands

- `pnpm dev` - run the API with source watching.
- `pnpm typecheck` - check TypeScript without emitting files.
- `pnpm build` - compile to `dist/`.
- `pnpm prisma:generate` - regenerate the Prisma client.
- `pnpm prisma:migrate:deploy` - apply checked-in migrations.

`pnpm-workspace.yaml` records the reviewed dependency build scripts required
by this project. Keep that allow-list explicit; do not switch to an allow-all
policy.
