# MMIT OPS v2 backend

The starter backend is an Express + TypeScript API with Prisma wired for MySQL
and Redis available through Docker Compose.

## First run

1. Copy `.env.example` to `.env` and replace `change-this-before-running` with
   one strong MySQL root password in both places.
2. Build and start the stack with `docker compose up --build -d`.
3. Confirm the API with `curl http://127.0.0.1:3000/api/health`.

The API is intentionally small at this stage. Prisma has a valid MySQL schema
and generated client, but no business models or migrations have been created.
Those come next with the OPS v2 domain design.

## Local commands

- `pnpm dev` - run the API with source watching.
- `pnpm typecheck` - check TypeScript without emitting files.
- `pnpm build` - compile to `dist/`.
- `pnpm prisma:generate` - regenerate the Prisma client.

`pnpm-workspace.yaml` records the reviewed dependency build scripts required
by this project. Keep that allow-list explicit; do not switch to an allow-all
policy.
