import express, { NextFunction, Request, RequestHandler, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import {
  ClientStatus,
  OpsUserRole,
  OpsUserStatus,
  Prisma,
  PrismaClient,
  ServiceTier,
  WorkOrderSource,
  WorkOrderStatus,
} from '@prisma/client';
import {
  bootstrapTokenMatches,
  createOpaqueToken,
  createRecoveryCodes,
  createTotpSecret,
  decryptSecret,
  encryptSecret,
  hashOpaqueToken,
  hashPassword,
  normalizeEmail,
  totpUri,
  verifyPassword,
  verifyTotp,
} from './auth.js';

// Load environment variables (db passwords, ports, secrets) securely
dotenv.config();

const app = express();
const PORT = process.env.PORT || 3000;
const prisma = new PrismaClient();

// Middleware for parsing JSON data and enabling Cross-Origin requests
app.use(cors());
app.use(express.json());

// Foundational base route to verify our server is alive and kicking
app.get('/api/health', async (_req: Request, res: Response) => {
  try {
    await prisma.$queryRaw`SELECT 1`;
    res.json({
      status: 'healthy',
      version: '2.0.0',
      database: 'connected',
      message: 'MMIT-OPS v2 Backend Core is operational!'
    });
  } catch (error) {
    res.status(503).json({
      status: 'degraded',
      database: 'unavailable',
      message: 'MMIT-OPS v2 cannot reach MySQL.',
    });
  }
});

const lifecycle: Record<WorkOrderStatus, WorkOrderStatus[]> = {
  REQUESTED: [WorkOrderStatus.ASSIGNED, WorkOrderStatus.CANCELLED],
  ASSIGNED: [WorkOrderStatus.SCHEDULED, WorkOrderStatus.CANCELLED],
  SCHEDULED: [WorkOrderStatus.IN_PROGRESS, WorkOrderStatus.CANCELLED],
  IN_PROGRESS: [WorkOrderStatus.COMPLETED, WorkOrderStatus.CANCELLED],
  COMPLETED: [WorkOrderStatus.INVOICED],
  INVOICED: [WorkOrderStatus.PAID],
  PAID: [],
  CANCELLED: [],
};

function isEnumValue<T extends Record<string, string>>(values: T, value: unknown): value is T[keyof T] {
  return typeof value === 'string' && Object.values(values).includes(value);
}

function parseId(value: string): bigint | null {
  return /^\d+$/.test(value) ? BigInt(value) : null;
}

function parseOptionalMoney(value: unknown): string | null | undefined {
  if (value === undefined) return undefined;
  if (value === null || value === '') return null;
  if (typeof value !== 'number' && typeof value !== 'string') return undefined;
  const normalized = String(value).trim();
  return /^\d+(\.\d{1,2})?$/.test(normalized) ? normalized : undefined;
}

function parseNullableText(value: unknown, maxLength: number): string | null | undefined {
  if (value === null) return null;
  if (typeof value !== 'string') return undefined;
  const normalized = value.trim();
  return normalized.length <= maxLength ? normalized || null : undefined;
}

function hasOwn(body: Record<string, unknown>, field: string): boolean {
  return Object.prototype.hasOwnProperty.call(body, field);
}

function isValidEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

type WorkOrderWithClient = Prisma.WorkOrderGetPayload<{ include: { client: true } }>;
type ClientWithCount = Prisma.ClientGetPayload<{
  include: { _count: { select: { workOrders: true } } };
}>;
type OpsAuditEventWithActor = Prisma.OpsAuditEventGetPayload<{ include: { actor: true } }>;

function serializeWorkOrder(workOrder: WorkOrderWithClient) {
  return {
    ...workOrder,
    id: workOrder.id.toString(),
    clientId: workOrder.clientId?.toString() ?? null,
    grossPay: workOrder.grossPay?.toString() ?? null,
    mileage: workOrder.mileage?.toString() ?? null,
    client: workOrder.client ? { ...workOrder.client, id: workOrder.client.id.toString() } : null,
  };
}

function serializeClient(client: ClientWithCount) {
  return {
    ...client,
    id: client.id.toString(),
    workOrderCount: client._count.workOrders,
    _count: undefined,
  };
}

function serializeAuditEvent(event: OpsAuditEventWithActor) {
  return {
    ...event,
    id: event.id.toString(),
    actorUserId: event.actorUserId.toString(),
    actor: {
      id: event.actor.id.toString(),
      email: event.actor.email,
      displayName: event.actor.displayName,
      role: event.actor.role,
    },
  };
}

type AuthenticatedUser = {
  userId: bigint;
  sessionId: string;
  email: string;
  displayName: string;
  role: OpsUserRole;
};

declare global {
  namespace Express {
    interface Request {
      auth?: AuthenticatedUser;
    }
  }
}

function apiUser(user: { id: bigint; email: string; displayName: string; role: OpsUserRole; status: OpsUserStatus }) {
  return {
    id: user.id.toString(),
    email: user.email,
    displayName: user.displayName,
    role: user.role,
    status: user.status,
  };
}

function requestBody(req: Request): Record<string, unknown> | null {
  return req.body && typeof req.body === 'object' && !Array.isArray(req.body)
    ? req.body as Record<string, unknown>
    : null;
}

function validDisplayName(value: unknown): string | null {
  const name = parseNullableText(value, 191);
  return name && name.length >= 2 ? name : null;
}

function validNewPassword(value: unknown): string | null {
  if (typeof value !== 'string' || value.length < 14 || value.length > 128) return null;
  return value;
}

function auditClientSnapshot(client: {
  id: bigint;
  name: string;
  status: ClientStatus;
  serviceTier: ServiceTier | null;
  syncroCustomerId: string | null;
  email: string | null;
  phone: string | null;
  notes: string | null;
}) {
  return {
    id: client.id.toString(),
    name: client.name,
    status: client.status,
    serviceTier: client.serviceTier,
    syncroCustomerId: client.syncroCustomerId,
    email: client.email,
    phone: client.phone,
    hasNotes: Boolean(client.notes),
  };
}

function auditWorkOrderSnapshot(workOrder: {
  id: bigint;
  source: WorkOrderSource;
  sourceReference: string;
  status: WorkOrderStatus;
  title: string;
  clientId: bigint | null;
  scheduledAt: Date | null;
  grossPay: Prisma.Decimal | null;
  mileage: Prisma.Decimal | null;
  driveMinutes: number;
  onsiteMinutes: number;
  adminMinutes: number;
  notes: string | null;
}) {
  return {
    id: workOrder.id.toString(),
    source: workOrder.source,
    sourceReference: workOrder.sourceReference,
    status: workOrder.status,
    title: workOrder.title,
    clientId: workOrder.clientId?.toString() ?? null,
    scheduledAt: workOrder.scheduledAt?.toISOString() ?? null,
    grossPay: workOrder.grossPay?.toString() ?? null,
    mileage: workOrder.mileage?.toString() ?? null,
    driveMinutes: workOrder.driveMinutes,
    onsiteMinutes: workOrder.onsiteMinutes,
    adminMinutes: workOrder.adminMinutes,
    hasNotes: Boolean(workOrder.notes),
  };
}

function authorizationToken(req: Request): string | null {
  const header = req.header('authorization');
  if (!header) return null;
  const match = /^Bearer ([A-Za-z0-9_-]{32,})$/.exec(header);
  return match?.[1] ?? null;
}

const requireAuth: RequestHandler = async (req, res, next) => {
  const rawToken = authorizationToken(req);
  if (!rawToken) {
    res.status(401).json({ error: 'A valid bearer session token is required.' });
    return;
  }
  try {
    const session = await prisma.opsSession.findUnique({
      where: { tokenHash: hashOpaqueToken(rawToken) },
      include: { user: true },
    });
    if (!session || session.revokedAt || session.expiresAt <= new Date() || session.user.status !== OpsUserStatus.ACTIVE) {
      res.status(401).json({ error: 'Session is invalid, expired, or no longer active.' });
      return;
    }
    req.auth = {
      userId: session.user.id,
      sessionId: session.id,
      email: session.user.email,
      displayName: session.user.displayName,
      role: session.user.role,
    };
    await prisma.opsSession.update({ where: { id: session.id }, data: { lastSeenAt: new Date() } });
    next();
  } catch (error) {
    next(error);
  }
};

function requireRoles(...roles: OpsUserRole[]): RequestHandler {
  return (req, res, next) => {
    if (!req.auth || !roles.includes(req.auth.role)) {
      res.status(403).json({ error: 'Your OPS role does not permit this action.' });
      return;
    }
    next();
  };
}

async function writeAuditEvent(
  tx: Prisma.TransactionClient,
  actor: AuthenticatedUser,
  action: string,
  subjectType: string,
  subjectId: string,
  details: Prisma.InputJsonValue,
): Promise<void> {
  await tx.opsAuditEvent.create({
    data: {
      actorUserId: actor.userId,
      action,
      subjectType,
      subjectId,
      details,
    },
  });
}

function mfaSetup(email: string, secret: string) {
  return {
    secret,
    otpauthUri: totpUri(email, secret),
    message: 'Add this TOTP secret to an authenticator app, then sign in with its six-digit code.',
  };
}

async function issueSession(userId: bigint): Promise<{ token: string; expiresAt: Date }> {
  const token = createOpaqueToken();
  const expiresAt = new Date(Date.now() + 12 * 60 * 60 * 1000);
  await prisma.opsSession.create({
    data: { userId, tokenHash: hashOpaqueToken(token), expiresAt },
  });
  return { token, expiresAt };
}

app.post('/api/v1/auth/bootstrap', async (req: Request, res: Response) => {
  const body = requestBody(req);
  if (!body) {
    res.status(400).json({ error: 'A JSON bootstrap object is required.' });
    return;
  }
  const email = normalizeEmail(body.email);
  const displayName = validDisplayName(body.displayName);
  const password = validNewPassword(body.password);
  if (!email || !displayName || !password) {
    res.status(400).json({ error: 'email, displayName, and a 14+ character password are required.' });
    return;
  }
  try {
    if (!bootstrapTokenMatches(body.bootstrapToken)) {
      res.status(401).json({ error: 'Bootstrap token is invalid.' });
      return;
    }
    if (await prisma.opsUser.count() !== 0) {
      res.status(409).json({ error: 'V2 already has an OPS user; bootstrap is permanently closed.' });
      return;
    }
    const secret = createTotpSecret();
    const user = await prisma.opsUser.create({
      data: {
        email,
        displayName,
        role: OpsUserRole.OWNER,
        passwordHash: await hashPassword(password),
        totpSecretCiphertext: encryptSecret(secret),
      },
    });
    res.status(201).json({ data: { user: apiUser(user), mfaSetup: mfaSetup(email, secret) } });
  } catch (error) {
    nextAuthError(error, res);
  }
});

app.post('/api/v1/auth/login', async (req: Request, res: Response) => {
  const body = requestBody(req);
  const email = body ? normalizeEmail(body.email) : null;
  const password = body?.password;
  if (!body || !email || typeof password !== 'string') {
    res.status(400).json({ error: 'email, password, and a TOTP or recovery code are required.' });
    return;
  }
  try {
    const user = await prisma.opsUser.findUnique({ where: { email } });
    if (!user || user.status === OpsUserStatus.DISABLED || !await verifyPassword(password, user.passwordHash)) {
      res.status(401).json({ error: 'Invalid sign-in details.' });
      return;
    }

    const totpValid = verifyTotp(decryptSecret(user.totpSecretCiphertext), body.totpCode);
    let recoveryCodeUsed = false;
    if (!totpValid && typeof body.recoveryCode === 'string') {
      const recovery = await prisma.opsRecoveryCode.findFirst({
        where: { userId: user.id, codeHash: hashOpaqueToken(body.recoveryCode.toUpperCase()), usedAt: null },
      });
      if (recovery) {
        await prisma.opsRecoveryCode.update({ where: { id: recovery.id }, data: { usedAt: new Date() } });
        recoveryCodeUsed = true;
      }
    }
    if (!totpValid && !recoveryCodeUsed) {
      res.status(401).json({ error: 'TOTP or an unused recovery code is required.' });
      return;
    }

    let recoveryCodes: string[] | undefined;
    let activeUser = user;
    if (user.status === OpsUserStatus.PENDING_MFA) {
      const newRecoveryCodes = createRecoveryCodes();
      activeUser = await prisma.$transaction(async (tx) => {
        const activated = await tx.opsUser.update({
          where: { id: user.id },
          data: { status: OpsUserStatus.ACTIVE, mfaVerifiedAt: new Date() },
        });
        await tx.opsRecoveryCode.createMany({
          data: newRecoveryCodes.map((code) => ({ userId: user.id, codeHash: hashOpaqueToken(code) })),
        });
        return activated;
      });
      recoveryCodes = newRecoveryCodes;
    }
    const session = await issueSession(activeUser.id);
    res.json({
      data: {
        token: session.token,
        expiresAt: session.expiresAt,
        user: apiUser(activeUser),
        recoveryCodes,
      },
    });
  } catch (error) {
    nextAuthError(error, res);
  }
});

app.post('/api/v1/auth/invitations/accept', async (req: Request, res: Response) => {
  const body = requestBody(req);
  const token = body?.token;
  const displayName = body ? validDisplayName(body.displayName) : null;
  const password = body ? validNewPassword(body.password) : null;
  if (typeof token !== 'string' || !displayName || !password) {
    res.status(400).json({ error: 'token, displayName, and a 14+ character password are required.' });
    return;
  }
  try {
    const invite = await prisma.opsInvite.findUnique({ where: { tokenHash: hashOpaqueToken(token) } });
    if (!invite || invite.acceptedAt || invite.revokedAt || invite.expiresAt <= new Date()) {
      res.status(401).json({ error: 'Invitation is invalid, expired, or already used.' });
      return;
    }
    const secret = createTotpSecret();
    const user = await prisma.$transaction(async (tx) => {
      const created = await tx.opsUser.create({
        data: {
          email: invite.email,
          displayName,
          role: invite.role,
          passwordHash: await hashPassword(password),
          totpSecretCiphertext: encryptSecret(secret),
        },
      });
      await tx.opsInvite.update({ where: { id: invite.id }, data: { acceptedAt: new Date() } });
      return created;
    });
    res.status(201).json({ data: { user: apiUser(user), mfaSetup: mfaSetup(user.email, secret) } });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2002') {
      res.status(409).json({ error: 'An OPS user already exists for this invitation email.' });
      return;
    }
    nextAuthError(error, res);
  }
});

app.get('/api/v1/auth/me', requireAuth, (req: Request, res: Response) => {
  res.json({ data: { id: req.auth!.userId.toString(), email: req.auth!.email, displayName: req.auth!.displayName, role: req.auth!.role } });
});

app.post('/api/v1/auth/logout', requireAuth, async (req: Request, res: Response, next: NextFunction) => {
  try {
    await prisma.opsSession.update({ where: { id: req.auth!.sessionId }, data: { revokedAt: new Date() } });
    res.status(204).send();
  } catch (error) {
    next(error);
  }
});

app.post('/api/v1/auth/invitations', requireAuth, requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const body = requestBody(req);
  const email = body ? normalizeEmail(body.email) : null;
  const role = body?.role ?? OpsUserRole.OPERATOR;
  if (!email || !isEnumValue(OpsUserRole, role)) {
    res.status(400).json({ error: 'A valid email and OPS role are required.' });
    return;
  }
  if (role === OpsUserRole.OWNER && req.auth!.role !== OpsUserRole.OWNER) {
    res.status(403).json({ error: 'Only an owner can invite another owner.' });
    return;
  }
  try {
    if (await prisma.opsUser.findUnique({ where: { email }, select: { id: true } })) {
      res.status(409).json({ error: 'An OPS user already exists for this email.' });
      return;
    }
    if (await prisma.opsInvite.findFirst({
      where: { email, acceptedAt: null, revokedAt: null, expiresAt: { gt: new Date() } },
      select: { id: true },
    })) {
      res.status(409).json({ error: 'An active invitation already exists for this email.' });
      return;
    }
    const token = createOpaqueToken();
    const expiresAt = new Date(Date.now() + 24 * 60 * 60 * 1000);
    const invite = await prisma.$transaction(async (tx) => {
      const created = await tx.opsInvite.create({
        data: { email, role, tokenHash: hashOpaqueToken(token), expiresAt, invitedById: req.auth!.userId },
      });
      await writeAuditEvent(tx, req.auth!, 'ops_user.invited', 'ops_invite', created.id, {
        email: created.email,
        role: created.role,
        expiresAt: created.expiresAt.toISOString(),
      });
      return created;
    });
    res.status(201).json({ data: { id: invite.id, email: invite.email, role: invite.role, expiresAt: invite.expiresAt, inviteToken: token } });
  } catch (error: unknown) {
    nextAuthError(error, res);
  }
});

app.get('/api/v1/audit-events', requireAuth, requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const rawLimit = req.query.limit;
  const subjectType = req.query.subjectType;
  const subjectId = req.query.subjectId;
  if (
    (rawLimit !== undefined && typeof rawLimit !== 'string')
    || (subjectType !== undefined && typeof subjectType !== 'string')
    || (subjectId !== undefined && typeof subjectId !== 'string')
  ) {
    res.status(400).json({ error: 'limit, subjectType, and subjectId must be single text values.' });
    return;
  }
  const limit = rawLimit === undefined ? 50 : Number(rawLimit);
  if (!Number.isInteger(limit) || limit < 1 || limit > 100) {
    res.status(400).json({ error: 'limit must be a whole number from 1 through 100.' });
    return;
  }
  if ((subjectType !== undefined && (subjectType.length < 1 || subjectType.length > 64))
    || (subjectId !== undefined && (subjectId.length < 1 || subjectId.length > 191))) {
    res.status(400).json({ error: 'subjectType or subjectId is outside its allowed length.' });
    return;
  }
  const events = await prisma.opsAuditEvent.findMany({
    where: {
      ...(subjectType ? { subjectType } : {}),
      ...(subjectId ? { subjectId } : {}),
    },
    include: { actor: true },
    orderBy: { id: 'desc' },
    take: limit,
  });
  res.json({ data: events.map(serializeAuditEvent) });
});

function nextAuthError(error: unknown, res: Response): void {
  console.error(error);
  res.status(500).json({ error: 'Authentication is not configured correctly.' });
}

app.use('/api/v1/clients', requireAuth);
app.use('/api/v1/work-orders', requireAuth);

app.get('/api/v1/clients', async (req: Request, res: Response) => {
  const search = req.query.search;
  if (search !== undefined && typeof search !== 'string') {
    res.status(400).json({ error: 'search must be a single text value.' });
    return;
  }
  const normalizedSearch = search?.trim();
  const clients = await prisma.client.findMany({
    where: normalizedSearch
      ? {
          OR: [
            { name: { contains: normalizedSearch } },
            { email: { contains: normalizedSearch } },
            { syncroCustomerId: { contains: normalizedSearch } },
          ],
        }
      : undefined,
    include: { _count: { select: { workOrders: true } } },
    orderBy: [{ status: 'asc' }, { name: 'asc' }],
    take: 100,
  });
  res.json({ data: clients.map(serializeClient) });
});

app.post('/api/v1/clients', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown> | null;
  if (!body || Array.isArray(body)) {
    res.status(400).json({ error: 'A JSON client object is required.' });
    return;
  }
  const name = parseNullableText(body.name, 191);
  if (!name) {
    res.status(400).json({ error: 'name is required and must be 191 characters or fewer.' });
    return;
  }
  const email = hasOwn(body, 'email') ? parseNullableText(body.email, 191) : null;
  const phone = hasOwn(body, 'phone') ? parseNullableText(body.phone, 64) : null;
  const syncroCustomerId = hasOwn(body, 'syncroCustomerId')
    ? parseNullableText(body.syncroCustomerId, 64)
    : null;
  const notes = hasOwn(body, 'notes') ? parseNullableText(body.notes, 10_000) : null;
  const status = body.status ?? ClientStatus.PROSPECT;
  const serviceTier = body.serviceTier ?? null;

  if (email === undefined || (email !== null && !isValidEmail(email))) {
    res.status(400).json({ error: 'email must be a valid address or null.' });
    return;
  }
  if (phone === undefined || syncroCustomerId === undefined || notes === undefined) {
    res.status(400).json({ error: 'phone, syncroCustomerId, and notes must be text or null within their allowed lengths.' });
    return;
  }
  if (!isEnumValue(ClientStatus, status) || !isEnumValue(ServiceTier, serviceTier) && serviceTier !== null) {
    res.status(400).json({ error: 'status or serviceTier is invalid.' });
    return;
  }

  try {
    const client = await prisma.$transaction(async (tx) => {
      const created = await tx.client.create({
        data: { name, email, phone, syncroCustomerId, notes, status, serviceTier },
        include: { _count: { select: { workOrders: true } } },
      });
      await writeAuditEvent(tx, req.auth!, 'client.created', 'client', created.id.toString(), {
        after: auditClientSnapshot(created),
        changedFields: ['name', 'email', 'phone', 'syncroCustomerId', 'notes', 'status', 'serviceTier'],
      });
      return created;
    });
    res.status(201).json({ data: serializeClient(client) });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2002') {
      res.status(409).json({ error: 'A client already uses that name or Syncro customer ID.' });
      return;
    }
    throw error;
  }
});

app.get('/api/v1/clients/:id', async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  if (id === null) {
    res.status(400).json({ error: 'id must be a positive integer.' });
    return;
  }
  const client = await prisma.client.findUnique({
    where: { id },
    include: {
      _count: { select: { workOrders: true } },
      workOrders: {
        include: { client: true },
        orderBy: { createdAt: 'desc' },
        take: 25,
      },
    },
  });
  if (!client) {
    res.status(404).json({ error: 'Client not found.' });
    return;
  }
  res.json({
    data: {
      ...serializeClient(client),
      recentWorkOrders: client.workOrders.map(serializeWorkOrder),
    },
  });
});

app.patch('/api/v1/clients/:id', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const body = req.body as Record<string, unknown> | null;
  if (id === null || !body || Array.isArray(body)) {
    res.status(400).json({ error: 'A valid client id and JSON update object are required.' });
    return;
  }

  const data: Prisma.ClientUpdateInput = {};
  if (hasOwn(body, 'name')) {
    const name = parseNullableText(body.name, 191);
    if (!name) {
      res.status(400).json({ error: 'name must be non-empty and 191 characters or fewer.' });
      return;
    }
    data.name = name;
  }
  if (hasOwn(body, 'email')) {
    const email = parseNullableText(body.email, 191);
    if (email === undefined || (email !== null && !isValidEmail(email))) {
      res.status(400).json({ error: 'email must be a valid address or null.' });
      return;
    }
    data.email = email;
  }
  for (const [field, maxLength] of [['phone', 64], ['syncroCustomerId', 64], ['notes', 10_000]] as const) {
    if (hasOwn(body, field)) {
      const value = parseNullableText(body[field], maxLength);
      if (value === undefined) {
        res.status(400).json({ error: `${field} must be text or null within its allowed length.` });
        return;
      }
      data[field] = value;
    }
  }
  if (hasOwn(body, 'status')) {
    if (!isEnumValue(ClientStatus, body.status)) {
      res.status(400).json({ error: 'status is invalid.' });
      return;
    }
    data.status = body.status;
  }
  if (hasOwn(body, 'serviceTier')) {
    if (body.serviceTier !== null && !isEnumValue(ServiceTier, body.serviceTier)) {
      res.status(400).json({ error: 'serviceTier must be MANAGE, PROTECT, GOVERN, or null.' });
      return;
    }
    data.serviceTier = body.serviceTier;
  }
  if (Object.keys(data).length === 0) {
    res.status(400).json({ error: 'Provide at least one editable client field.' });
    return;
  }

  try {
    const client = await prisma.$transaction(async (tx) => {
      const before = await tx.client.findUnique({ where: { id } });
      if (!before) return null;
      const updated = await tx.client.update({
        where: { id },
        data,
        include: { _count: { select: { workOrders: true } } },
      });
      await writeAuditEvent(tx, req.auth!, 'client.updated', 'client', updated.id.toString(), {
        before: auditClientSnapshot(before),
        after: auditClientSnapshot(updated),
        changedFields: Object.keys(data),
      });
      return updated;
    });
    if (!client) {
      res.status(404).json({ error: 'Client not found.' });
      return;
    }
    res.json({ data: serializeClient(client) });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error) {
      if (error.code === 'P2002') {
        res.status(409).json({ error: 'A client already uses that name or Syncro customer ID.' });
        return;
      }
    }
    throw error;
  }
});

app.get('/api/v1/work-orders', async (req: Request, res: Response) => {
  const { status } = req.query;
  if (status !== undefined && !isEnumValue(WorkOrderStatus, status)) {
    res.status(400).json({ error: 'status must be a valid work-order status.' });
    return;
  }

  const workOrders = await prisma.workOrder.findMany({
    where: status ? { status } : undefined,
    include: { client: true },
    orderBy: [{ scheduledAt: 'asc' }, { id: 'desc' }],
    take: 100,
  });
  res.json({ data: workOrders.map(serializeWorkOrder) });
});

app.post('/api/v1/work-orders', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const { source, sourceReference, title, clientId, scheduledAt, grossPay, notes } = req.body ?? {};
  if (!isEnumValue(WorkOrderSource, source)) {
    res.status(400).json({ error: 'source must be FIELD_NATION, MANUAL, SYNCRO, or OTHER.' });
    return;
  }
  if (typeof sourceReference !== 'string' || !sourceReference.trim() || sourceReference.length > 191) {
    res.status(400).json({ error: 'sourceReference is required and must be 191 characters or fewer.' });
    return;
  }
  if (typeof title !== 'string' || !title.trim() || title.length > 255) {
    res.status(400).json({ error: 'title is required and must be 255 characters or fewer.' });
    return;
  }
  const parsedClientId = clientId === undefined || clientId === null ? null : parseId(String(clientId));
  if (clientId !== undefined && clientId !== null && parsedClientId === null) {
    res.status(400).json({ error: 'clientId must be a positive integer.' });
    return;
  }
  const parsedPay = parseOptionalMoney(grossPay);
  if (parsedPay === undefined) {
    res.status(400).json({ error: 'grossPay must be a non-negative amount with at most two decimals.' });
    return;
  }
  const parsedScheduledAt = scheduledAt === undefined || scheduledAt === null || scheduledAt === ''
    ? null
    : new Date(scheduledAt);
  if (parsedScheduledAt && Number.isNaN(parsedScheduledAt.getTime())) {
    res.status(400).json({ error: 'scheduledAt must be a valid ISO timestamp.' });
    return;
  }

  try {
    const workOrder = await prisma.$transaction(async (tx) => {
      const created = await tx.workOrder.create({
        data: {
          source,
          sourceReference: sourceReference.trim(),
          title: title.trim(),
          clientId: parsedClientId,
          scheduledAt: parsedScheduledAt,
          grossPay: parsedPay,
          notes: typeof notes === 'string' ? notes.trim() || null : null,
        },
        include: { client: true },
      });
      await writeAuditEvent(tx, req.auth!, 'work_order.created', 'work_order', created.id.toString(), {
        after: auditWorkOrderSnapshot(created),
        changedFields: ['source', 'sourceReference', 'title', 'clientId', 'scheduledAt', 'grossPay', 'notes'],
      });
      return created;
    });
    res.status(201).json({ data: serializeWorkOrder(workOrder) });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2002') {
      res.status(409).json({ error: 'A work order with that source and reference already exists.' });
      return;
    }
    if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2003') {
      res.status(400).json({ error: 'clientId does not refer to an existing client.' });
      return;
    }
    throw error;
  }
});

app.get('/api/v1/work-orders/:id', async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  if (id === null) {
    res.status(400).json({ error: 'id must be a positive integer.' });
    return;
  }
  const workOrder = await prisma.workOrder.findUnique({ where: { id }, include: { client: true } });
  if (!workOrder) {
    res.status(404).json({ error: 'Work order not found.' });
    return;
  }
  res.json({ data: serializeWorkOrder(workOrder) });
});

app.patch('/api/v1/work-orders/:id/client', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const body = req.body as Record<string, unknown> | null;
  if (id === null || !body || Array.isArray(body) || !hasOwn(body, 'clientId')) {
    res.status(400).json({ error: 'A valid work-order id and explicit clientId are required.' });
    return;
  }
  const clientId = body.clientId === null ? null : parseId(String(body.clientId));
  if (clientId === null && body.clientId !== null) {
    res.status(400).json({ error: 'clientId must be a positive integer or null to unlink.' });
    return;
  }
  if (clientId !== null) {
    const client = await prisma.client.findUnique({ where: { id: clientId }, select: { id: true } });
    if (!client) {
      res.status(400).json({ error: 'clientId does not refer to an existing client.' });
      return;
    }
  }
  try {
    const workOrder = await prisma.$transaction(async (tx) => {
      const before = await tx.workOrder.findUnique({ where: { id } });
      if (!before) return null;
      const updated = await tx.workOrder.update({
        where: { id },
        data: { clientId },
        include: { client: true },
      });
      await writeAuditEvent(tx, req.auth!, 'work_order.client_changed', 'work_order', updated.id.toString(), {
        before: auditWorkOrderSnapshot(before),
        after: auditWorkOrderSnapshot(updated),
        changedFields: ['clientId'],
      });
      return updated;
    });
    if (!workOrder) {
      res.status(404).json({ error: 'Work order not found.' });
      return;
    }
    res.json({ data: serializeWorkOrder(workOrder) });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2025') {
      res.status(404).json({ error: 'Work order not found.' });
      return;
    }
    throw error;
  }
});

app.patch('/api/v1/work-orders/:id/status', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const nextStatus = req.body?.status;
  if (id === null || !isEnumValue(WorkOrderStatus, nextStatus)) {
    res.status(400).json({ error: 'A valid work-order id and status are required.' });
    return;
  }
  const outcome = await prisma.$transaction(async (tx) => {
    const before = await tx.workOrder.findUnique({ where: { id } });
    if (!before) return { kind: 'not_found' as const };
    if (!lifecycle[before.status].includes(nextStatus)) {
      return { kind: 'invalid_transition' as const, from: before.status };
    }
    const updated = await tx.workOrder.update({
      where: { id },
      data: { status: nextStatus },
      include: { client: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.status_changed', 'work_order', updated.id.toString(), {
      before: auditWorkOrderSnapshot(before),
      after: auditWorkOrderSnapshot(updated),
      changedFields: ['status'],
    });
    return { kind: 'updated' as const, workOrder: updated };
  });
  if (outcome.kind === 'not_found') {
    res.status(404).json({ error: 'Work order not found.' });
    return;
  }
  if (outcome.kind === 'invalid_transition') {
    res.status(409).json({ error: `Cannot move ${outcome.from} directly to ${nextStatus}.` });
    return;
  }
  res.json({ data: serializeWorkOrder(outcome.workOrder) });
});

app.use((error: unknown, _req: Request, res: Response, _next: express.NextFunction) => {
  console.error(error);
  res.status(500).json({ error: 'Unexpected server error.' });
});

// Start the server listening process
app.listen(PORT, () => {
  console.log(`🚀 Modernized backend running smoothly on port ${PORT}`);
});
