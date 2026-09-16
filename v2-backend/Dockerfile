FROM node:20-bookworm-slim AS build

WORKDIR /app

RUN npm install -g pnpm@10.34.5

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY tsconfig.json ./
COPY prisma ./prisma

RUN pnpm prisma generate

COPY src ./src
RUN pnpm build

FROM build AS runtime
ENV NODE_ENV=production

EXPOSE 3000

CMD ["node", "dist/index.js"]
