import express, { NextFunction, Request, RequestHandler, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import { createHash, randomUUID } from 'node:crypto';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve, sep } from 'node:path';
import {
  ClientStatus,
  OpsUserRole,
  OpsUserStatus,
  Prisma,
  PrismaClient,
  ServiceTier,
  WorkOrderAttachmentKind,
  WorkOrderExpenseCategory,
  WorkOrderInvoiceLineType,
  WorkOrderInvoiceStatus,
  WorkOrderMaterialSource,
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

function parsePositiveQuantity(value: unknown): string | undefined {
  if (typeof value !== 'number' && typeof value !== 'string') return undefined;
  const normalized = String(value).trim();
  if (!/^\d+(\.\d{1,3})?$/.test(normalized)) return undefined;
  const parsed = Number(normalized);
  return Number.isFinite(parsed) && parsed > 0 && parsed <= 1_000_000 ? normalized : undefined;
}

function parseOptionalTimestamp(value: unknown): Date | null | undefined {
  if (value === null || value === '') return null;
  if (typeof value !== 'string') return undefined;
  const normalized = value.trim();
  if (!normalized) return null;
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2})$/.test(normalized)) {
    return undefined;
  }
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? undefined : parsed;
}

function parseOptionalMinutes(value: unknown): number | undefined {
  if (typeof value === 'number') {
    return Number.isInteger(value) && value >= 0 && value <= 10_080 ? value : undefined;
  }
  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    const parsed = Number(value.trim());
    return Number.isSafeInteger(parsed) && parsed <= 10_080 ? parsed : undefined;
  }
  return undefined;
}

const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

function attachmentOriginalName(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const name = value.trim();
  return /^[A-Za-z0-9][A-Za-z0-9._ -]{0,190}$/.test(name) && !name.includes('..') ? name : null;
}

function attachmentMediaType(value: unknown): string | null {
  if (value === undefined || value === null || value === '') return 'application/octet-stream';
  if (typeof value !== 'string') return null;
  const mediaType = value.trim().toLowerCase();
  return /^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/.test(mediaType) ? mediaType : null;
}

function attachmentStoragePath(storageKey: string): string {
  const match = /^(\d+)\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/.exec(storageKey);
  const configuredRoot = process.env.V2_ATTACHMENT_STORAGE_ROOT;
  if (!match || !configuredRoot) throw new Error('Attachment storage is not configured correctly.');
  const root = resolve(configuredRoot);
  const target = resolve(root, match[1], match[2]);
  if (!target.startsWith(`${root}${sep}`)) throw new Error('Attachment storage path is invalid.');
  return target;
}

function auditAttachmentSnapshot(attachment: {
  id: string;
  kind: WorkOrderAttachmentKind;
  originalName: string;
  mediaType: string;
  byteSize: number;
  sha256: string;
  createdAt: Date;
}) {
  return {
    id: attachment.id,
    kind: attachment.kind,
    originalName: attachment.originalName,
    mediaType: attachment.mediaType,
    byteSize: attachment.byteSize,
    sha256: attachment.sha256,
    createdAt: attachment.createdAt.toISOString(),
  };
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
type WorkOrderAttachmentWithUsers = Prisma.WorkOrderAttachmentGetPayload<{
  include: { uploadedBy: true; deletedBy: true };
}>;
type WorkOrderExpenseWithUsers = Prisma.WorkOrderExpenseGetPayload<{
  include: { createdBy: true; voidedBy: true };
}>;
type WorkOrderMaterialWithUsers = Prisma.WorkOrderMaterialGetPayload<{
  include: { createdBy: true; voidedBy: true };
}>;
type WorkOrderInvoiceWithPeople = Prisma.WorkOrderInvoiceGetPayload<{
  include: { lines: true; createdBy: true; issuedBy: true; voidedBy: true };
}>;

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

function serializeAttachment(attachment: WorkOrderAttachmentWithUsers) {
  return {
    id: attachment.id,
    kind: attachment.kind,
    originalName: attachment.originalName,
    mediaType: attachment.mediaType,
    byteSize: attachment.byteSize,
    sha256: attachment.sha256,
    createdAt: attachment.createdAt,
    deletedAt: attachment.deletedAt,
    uploadedBy: {
      id: attachment.uploadedBy.id.toString(),
      email: attachment.uploadedBy.email,
      displayName: attachment.uploadedBy.displayName,
    },
    deletedBy: attachment.deletedBy
      ? {
          id: attachment.deletedBy.id.toString(),
          email: attachment.deletedBy.email,
          displayName: attachment.deletedBy.displayName,
        }
      : null,
  };
}

function serializeExpense(expense: WorkOrderExpenseWithUsers) {
  return {
    ...expense,
    id: expense.id.toString(),
    workOrderId: expense.workOrderId.toString(),
    createdById: expense.createdById.toString(),
    voidedById: expense.voidedById?.toString() ?? null,
    costAmount: expense.costAmount.toString(),
    billAmount: expense.billAmount?.toString() ?? null,
    createdBy: { id: expense.createdBy.id.toString(), email: expense.createdBy.email, displayName: expense.createdBy.displayName },
    voidedBy: expense.voidedBy
      ? { id: expense.voidedBy.id.toString(), email: expense.voidedBy.email, displayName: expense.voidedBy.displayName }
      : null,
  };
}

function serializeMaterial(material: WorkOrderMaterialWithUsers) {
  return {
    ...material,
    id: material.id.toString(),
    workOrderId: material.workOrderId.toString(),
    createdById: material.createdById.toString(),
    voidedById: material.voidedById?.toString() ?? null,
    quantity: material.quantity.toString(),
    unitCost: material.unitCost.toString(),
    unitPrice: material.unitPrice?.toString() ?? null,
    createdBy: { id: material.createdBy.id.toString(), email: material.createdBy.email, displayName: material.createdBy.displayName },
    voidedBy: material.voidedBy
      ? { id: material.voidedBy.id.toString(), email: material.voidedBy.email, displayName: material.voidedBy.displayName }
      : null,
  };
}

function serializeInvoice(invoice: WorkOrderInvoiceWithPeople) {
  return {
    ...invoice,
    id: invoice.id.toString(),
    workOrderId: invoice.workOrderId.toString(),
    createdById: invoice.createdById.toString(),
    issuedById: invoice.issuedById?.toString() ?? null,
    voidedById: invoice.voidedById?.toString() ?? null,
    totalAmount: invoice.totalAmount.toString(),
    lines: invoice.lines.map((line) => ({
      ...line,
      id: line.id.toString(), invoiceId: line.invoiceId.toString(),
      quantity: line.quantity.toString(), unitAmount: line.unitAmount.toString(), totalAmount: line.totalAmount.toString(),
    })),
    createdBy: { id: invoice.createdBy.id.toString(), email: invoice.createdBy.email, displayName: invoice.createdBy.displayName },
    issuedBy: invoice.issuedBy ? { id: invoice.issuedBy.id.toString(), email: invoice.issuedBy.email, displayName: invoice.issuedBy.displayName } : null,
    voidedBy: invoice.voidedBy ? { id: invoice.voidedBy.id.toString(), email: invoice.voidedBy.email, displayName: invoice.voidedBy.displayName } : null,
  };
}

function auditExpenseSnapshot(expense: {
  id: bigint; category: WorkOrderExpenseCategory; description: string; costAmount: Prisma.Decimal; billAmount: Prisma.Decimal | null;
  occurredAt: Date | null; notes: string | null; voidedAt: Date | null;
}) {
  return {
    id: expense.id.toString(), category: expense.category, description: expense.description,
    costAmount: expense.costAmount.toString(), billAmount: expense.billAmount?.toString() ?? null,
    occurredAt: expense.occurredAt?.toISOString() ?? null, hasNotes: Boolean(expense.notes), voidedAt: expense.voidedAt?.toISOString() ?? null,
  };
}

function auditMaterialSnapshot(material: {
  id: bigint; source: WorkOrderMaterialSource; description: string; sku: string | null; quantity: Prisma.Decimal;
  unitCost: Prisma.Decimal; unitPrice: Prisma.Decimal | null; notes: string | null; voidedAt: Date | null;
}) {
  return {
    id: material.id.toString(), source: material.source, description: material.description, sku: material.sku,
    quantity: material.quantity.toString(), unitCost: material.unitCost.toString(), unitPrice: material.unitPrice?.toString() ?? null,
    hasNotes: Boolean(material.notes), voidedAt: material.voidedAt?.toISOString() ?? null,
  };
}

function workOrderFinanciallyLocked(status: WorkOrderStatus): boolean {
  return status === WorkOrderStatus.INVOICED || status === WorkOrderStatus.PAID;
}

function auditInvoiceSnapshot(invoice: { id: bigint; status: WorkOrderInvoiceStatus; clientName: string; clientEmail: string | null; totalAmount: Prisma.Decimal; issuedAt: Date | null; voidedAt: Date | null; }) {
  return {
    id: invoice.id.toString(), status: invoice.status, clientName: invoice.clientName, clientEmail: invoice.clientEmail,
    totalAmount: invoice.totalAmount.toString(), issuedAt: invoice.issuedAt?.toISOString() ?? null, voidedAt: invoice.voidedAt?.toISOString() ?? null,
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

app.get('/api/v1/work-orders/:id/costs', async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  if (workOrderId === null) {
    res.status(400).json({ error: 'id must be a positive integer.' });
    return;
  }
  const workOrder = await prisma.workOrder.findUnique({ where: { id: workOrderId }, select: { id: true } });
  if (!workOrder) {
    res.status(404).json({ error: 'Work order not found.' });
    return;
  }
  const [expenses, materials] = await Promise.all([
    prisma.workOrderExpense.findMany({
      where: { workOrderId, voidedAt: null }, include: { createdBy: true, voidedBy: true }, orderBy: [{ occurredAt: 'desc' }, { id: 'desc' }],
    }),
    prisma.workOrderMaterial.findMany({
      where: { workOrderId, voidedAt: null }, include: { createdBy: true, voidedBy: true }, orderBy: { id: 'desc' },
    }),
  ]);
  const expenseCost = expenses.reduce((total, item) => total.plus(item.costAmount), new Prisma.Decimal(0));
  const expenseBill = expenses.reduce((total, item) => total.plus(item.billAmount ?? 0), new Prisma.Decimal(0));
  const materialCost = materials.reduce((total, item) => total.plus(item.quantity.mul(item.unitCost)), new Prisma.Decimal(0));
  const materialBill = materials.reduce((total, item) => total.plus(item.quantity.mul(item.unitPrice ?? 0)), new Prisma.Decimal(0));
  res.json({
    data: {
      expenses: expenses.map(serializeExpense),
      materials: materials.map(serializeMaterial),
      totals: {
        expenseCost: expenseCost.toFixed(2), expenseBill: expenseBill.toFixed(2),
        materialCost: materialCost.toFixed(2), materialBill: materialBill.toFixed(2),
        totalCost: expenseCost.plus(materialCost).toFixed(2), totalBill: expenseBill.plus(materialBill).toFixed(2),
      },
    },
  });
});

app.post('/api/v1/work-orders/:id/expenses', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const body = requestBody(req);
  const description = body ? parseNullableText(body.description, 255) : undefined;
  const category = body?.category ?? WorkOrderExpenseCategory.OTHER;
  const costAmount = body ? parseOptionalMoney(body.costAmount) : undefined;
  const billAmount = body && hasOwn(body, 'billAmount') ? parseOptionalMoney(body.billAmount) : null;
  const occurredAt = body && hasOwn(body, 'occurredAt') ? parseOptionalTimestamp(body.occurredAt) : null;
  const notes = body && hasOwn(body, 'notes') ? parseNullableText(body.notes, 10_000) : null;
  if (workOrderId === null || !body || !description || !isEnumValue(WorkOrderExpenseCategory, category) || costAmount === undefined || costAmount === null || billAmount === undefined || occurredAt === undefined || notes === undefined) {
    res.status(400).json({ error: 'Provide description, category, non-negative costAmount, optional billAmount, optional occurredAt, and optional notes.' });
    return;
  }
  const outcome = await prisma.$transaction(async (tx) => {
    const workOrder = await tx.workOrder.findUnique({ where: { id: workOrderId }, select: { status: true } });
    if (!workOrder) return { kind: 'not_found' as const };
    if (workOrderFinanciallyLocked(workOrder.status)) return { kind: 'locked' as const };
    const created = await tx.workOrderExpense.create({
      data: { workOrderId, category, description, costAmount, billAmount, occurredAt, notes, createdById: req.auth!.userId },
      include: { createdBy: true, voidedBy: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.expense_created', 'work_order', workOrderId.toString(), { expense: auditExpenseSnapshot(created) });
    return { kind: 'created' as const, expense: created };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Work order not found.' });
  if (outcome.kind === 'locked') return void res.status(409).json({ error: 'Invoiced or paid work orders require a later accounting adjustment workflow.' });
  res.status(201).json({ data: serializeExpense(outcome.expense) });
});

app.post('/api/v1/work-orders/:id/materials', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const body = requestBody(req);
  const description = body ? parseNullableText(body.description, 255) : undefined;
  const source = body?.source ?? WorkOrderMaterialSource.PURCHASE;
  const sku = body && hasOwn(body, 'sku') ? parseNullableText(body.sku, 100) : null;
  const quantity = body ? parsePositiveQuantity(body.quantity) : undefined;
  const unitCost = body ? parseOptionalMoney(body.unitCost) : undefined;
  const unitPrice = body && hasOwn(body, 'unitPrice') ? parseOptionalMoney(body.unitPrice) : null;
  const notes = body && hasOwn(body, 'notes') ? parseNullableText(body.notes, 10_000) : null;
  if (workOrderId === null || !body || !description || !isEnumValue(WorkOrderMaterialSource, source) || sku === undefined || !quantity || unitCost === undefined || unitCost === null || unitPrice === undefined || notes === undefined) {
    res.status(400).json({ error: 'Provide description, source, positive quantity, non-negative unitCost, optional unitPrice, optional sku, and optional notes.' });
    return;
  }
  const outcome = await prisma.$transaction(async (tx) => {
    const workOrder = await tx.workOrder.findUnique({ where: { id: workOrderId }, select: { status: true } });
    if (!workOrder) return { kind: 'not_found' as const };
    if (workOrderFinanciallyLocked(workOrder.status)) return { kind: 'locked' as const };
    const created = await tx.workOrderMaterial.create({
      data: { workOrderId, source, description, sku, quantity, unitCost, unitPrice, notes, createdById: req.auth!.userId },
      include: { createdBy: true, voidedBy: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.material_created', 'work_order', workOrderId.toString(), { material: auditMaterialSnapshot(created) });
    return { kind: 'created' as const, material: created };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Work order not found.' });
  if (outcome.kind === 'locked') return void res.status(409).json({ error: 'Invoiced or paid work orders require a later accounting adjustment workflow.' });
  res.status(201).json({ data: serializeMaterial(outcome.material) });
});

app.delete('/api/v1/work-orders/:id/expenses/:expenseId', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const expenseId = typeof req.params.expenseId === 'string' ? parseId(req.params.expenseId) : null;
  if (workOrderId === null || expenseId === null) return void res.status(400).json({ error: 'Valid work-order and expense ids are required.' });
  const outcome = await prisma.$transaction(async (tx) => {
    const existing = await tx.workOrderExpense.findFirst({ where: { id: expenseId, workOrderId, voidedAt: null }, include: { createdBy: true, voidedBy: true, workOrder: { select: { status: true } } } });
    if (!existing) return { kind: 'not_found' as const };
    if (workOrderFinanciallyLocked(existing.workOrder.status)) return { kind: 'locked' as const };
    const voided = await tx.workOrderExpense.update({ where: { id: expenseId }, data: { voidedAt: new Date(), voidedById: req.auth!.userId }, include: { createdBy: true, voidedBy: true } });
    await writeAuditEvent(tx, req.auth!, 'work_order.expense_voided', 'work_order', workOrderId.toString(), { expense: auditExpenseSnapshot(existing) });
    return { kind: 'voided' as const, expense: voided };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Expense not found.' });
  if (outcome.kind === 'locked') return void res.status(409).json({ error: 'Invoiced or paid work orders require a later accounting adjustment workflow.' });
  res.json({ data: serializeExpense(outcome.expense) });
});

app.delete('/api/v1/work-orders/:id/materials/:materialId', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const materialId = typeof req.params.materialId === 'string' ? parseId(req.params.materialId) : null;
  if (workOrderId === null || materialId === null) return void res.status(400).json({ error: 'Valid work-order and material ids are required.' });
  const outcome = await prisma.$transaction(async (tx) => {
    const existing = await tx.workOrderMaterial.findFirst({ where: { id: materialId, workOrderId, voidedAt: null }, include: { createdBy: true, voidedBy: true, workOrder: { select: { status: true } } } });
    if (!existing) return { kind: 'not_found' as const };
    if (workOrderFinanciallyLocked(existing.workOrder.status)) return { kind: 'locked' as const };
    const voided = await tx.workOrderMaterial.update({ where: { id: materialId }, data: { voidedAt: new Date(), voidedById: req.auth!.userId }, include: { createdBy: true, voidedBy: true } });
    await writeAuditEvent(tx, req.auth!, 'work_order.material_voided', 'work_order', workOrderId.toString(), { material: auditMaterialSnapshot(existing) });
    return { kind: 'voided' as const, material: voided };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Material not found.' });
  if (outcome.kind === 'locked') return void res.status(409).json({ error: 'Invoiced or paid work orders require a later accounting adjustment workflow.' });
  res.json({ data: serializeMaterial(outcome.material) });
});

app.get('/api/v1/work-orders/:id/invoice-drafts', async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  if (workOrderId === null) return void res.status(400).json({ error: 'id must be a positive integer.' });
  const invoices = await prisma.workOrderInvoice.findMany({
    where: { workOrderId },
    include: { lines: { orderBy: { id: 'asc' } }, createdBy: true, issuedBy: true, voidedBy: true },
    orderBy: { id: 'desc' },
  });
  res.json({ data: invoices.map(serializeInvoice) });
});

app.post('/api/v1/work-orders/:id/invoice-drafts', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  if (workOrderId === null) return void res.status(400).json({ error: 'id must be a positive integer.' });
  const outcome = await prisma.$transaction(async (tx) => {
    const workOrder = await tx.workOrder.findUnique({
      where: { id: workOrderId },
      include: {
        client: true,
        expenses: { where: { voidedAt: null, billAmount: { not: null } } },
        materials: { where: { voidedAt: null, unitPrice: { not: null } } },
      },
    });
    if (!workOrder) return { kind: 'not_found' as const };
    if (workOrder.source === WorkOrderSource.FIELD_NATION) return { kind: 'provider_only' as const };
    if (workOrder.status !== WorkOrderStatus.COMPLETED) return { kind: 'not_completed' as const, status: workOrder.status };
    if (!workOrder.client) return { kind: 'missing_client' as const };
    const activeInvoice = await tx.workOrderInvoice.findFirst({ where: { workOrderId, status: { in: [WorkOrderInvoiceStatus.DRAFT, WorkOrderInvoiceStatus.ISSUED] } }, select: { id: true } });
    if (activeInvoice) return { kind: 'exists' as const, invoiceId: activeInvoice.id };

    const lines: Array<{ lineType: WorkOrderInvoiceLineType; description: string; quantity: Prisma.Decimal; unitAmount: Prisma.Decimal; totalAmount: Prisma.Decimal; sourceRecordId?: string }> = [];
    if (workOrder.grossPay && workOrder.grossPay.gt(0)) {
      lines.push({ lineType: WorkOrderInvoiceLineType.LABOR, description: workOrder.title, quantity: new Prisma.Decimal(1), unitAmount: workOrder.grossPay, totalAmount: workOrder.grossPay, sourceRecordId: workOrder.id.toString() });
    }
    for (const material of workOrder.materials) {
      const totalAmount = material.quantity.mul(material.unitPrice!);
      lines.push({ lineType: WorkOrderInvoiceLineType.MATERIAL, description: material.description, quantity: material.quantity, unitAmount: material.unitPrice!, totalAmount, sourceRecordId: material.id.toString() });
    }
    for (const expense of workOrder.expenses) {
      lines.push({ lineType: WorkOrderInvoiceLineType.EXPENSE, description: expense.description, quantity: new Prisma.Decimal(1), unitAmount: expense.billAmount!, totalAmount: expense.billAmount!, sourceRecordId: expense.id.toString() });
    }
    const totalAmount = lines.reduce((total, line) => total.plus(line.totalAmount), new Prisma.Decimal(0));
    if (lines.length === 0 || totalAmount.lte(0)) return { kind: 'no_lines' as const };
    const created = await tx.workOrderInvoice.create({
      data: {
        workOrderId, clientName: workOrder.client.name, clientEmail: workOrder.client.email, totalAmount, createdById: req.auth!.userId,
        lines: { create: lines },
      },
      include: { lines: { orderBy: { id: 'asc' } }, createdBy: true, issuedBy: true, voidedBy: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.invoice_draft_created', 'work_order', workOrderId.toString(), {
      invoice: auditInvoiceSnapshot(created), lineCount: lines.length,
    });
    return { kind: 'created' as const, invoice: created };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Work order not found.' });
  if (outcome.kind === 'provider_only') return void res.status(409).json({ error: 'FieldNation work orders are payout-only and cannot create customer invoice drafts.' });
  if (outcome.kind === 'not_completed') return void res.status(409).json({ error: `Only completed direct-work orders can create an invoice draft (current status: ${outcome.status}).` });
  if (outcome.kind === 'missing_client') return void res.status(409).json({ error: 'Link a client before creating a direct-work invoice draft.' });
  if (outcome.kind === 'exists') return void res.status(409).json({ error: `An active invoice draft already exists (id ${outcome.invoiceId.toString()}).` });
  if (outcome.kind === 'no_lines') return void res.status(409).json({ error: 'An invoice draft needs positive billable labor, materials, or reimbursable expenses.' });
  res.status(201).json({ data: serializeInvoice(outcome.invoice) });
});

app.post('/api/v1/work-orders/:id/invoice-drafts/:invoiceId/issue', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const invoiceId = typeof req.params.invoiceId === 'string' ? parseId(req.params.invoiceId) : null;
  if (workOrderId === null || invoiceId === null) return void res.status(400).json({ error: 'Valid work-order and invoice ids are required.' });
  const outcome = await prisma.$transaction(async (tx) => {
    const invoice = await tx.workOrderInvoice.findFirst({
      where: { id: invoiceId, workOrderId },
      include: { lines: { orderBy: { id: 'asc' } }, createdBy: true, issuedBy: true, voidedBy: true, workOrder: { select: { status: true } } },
    });
    if (!invoice) return { kind: 'not_found' as const };
    if (invoice.status !== WorkOrderInvoiceStatus.DRAFT) return { kind: 'not_draft' as const, status: invoice.status };
    if (invoice.workOrder.status !== WorkOrderStatus.COMPLETED) return { kind: 'invalid_status' as const, status: invoice.workOrder.status };
    const issued = await tx.workOrderInvoice.update({
      where: { id: invoiceId }, data: { status: WorkOrderInvoiceStatus.ISSUED, issuedAt: new Date(), issuedById: req.auth!.userId },
      include: { lines: { orderBy: { id: 'asc' } }, createdBy: true, issuedBy: true, voidedBy: true },
    });
    await tx.workOrder.update({ where: { id: workOrderId }, data: { status: WorkOrderStatus.INVOICED } });
    await writeAuditEvent(tx, req.auth!, 'work_order.invoice_issued', 'work_order', workOrderId.toString(), { invoice: auditInvoiceSnapshot(issued), changedFields: ['invoice.status', 'work_order.status'] });
    return { kind: 'issued' as const, invoice: issued };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Invoice draft not found.' });
  if (outcome.kind === 'not_draft') return void res.status(409).json({ error: `Only draft invoices can be issued (current status: ${outcome.status}).` });
  if (outcome.kind === 'invalid_status') return void res.status(409).json({ error: `The work order must remain completed to issue this invoice (current status: ${outcome.status}).` });
  res.json({ data: serializeInvoice(outcome.invoice) });
});

app.post('/api/v1/work-orders/:id/invoice-drafts/:invoiceId/void', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const invoiceId = typeof req.params.invoiceId === 'string' ? parseId(req.params.invoiceId) : null;
  if (workOrderId === null || invoiceId === null) return void res.status(400).json({ error: 'Valid work-order and invoice ids are required.' });
  const outcome = await prisma.$transaction(async (tx) => {
    const existing = await tx.workOrderInvoice.findFirst({ where: { id: invoiceId, workOrderId }, include: { lines: true, createdBy: true, issuedBy: true, voidedBy: true } });
    if (!existing) return { kind: 'not_found' as const };
    if (existing.status !== WorkOrderInvoiceStatus.DRAFT) return { kind: 'not_draft' as const, status: existing.status };
    const voided = await tx.workOrderInvoice.update({
      where: { id: invoiceId }, data: { status: WorkOrderInvoiceStatus.VOIDED, voidedAt: new Date(), voidedById: req.auth!.userId },
      include: { lines: { orderBy: { id: 'asc' } }, createdBy: true, issuedBy: true, voidedBy: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.invoice_draft_voided', 'work_order', workOrderId.toString(), { invoice: auditInvoiceSnapshot(existing) });
    return { kind: 'voided' as const, invoice: voided };
  });
  if (outcome.kind === 'not_found') return void res.status(404).json({ error: 'Invoice draft not found.' });
  if (outcome.kind === 'not_draft') return void res.status(409).json({ error: `Only draft invoices can be voided (current status: ${outcome.status}).` });
  res.json({ data: serializeInvoice(outcome.invoice) });
});

app.get('/api/v1/work-orders/:id/attachments', async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  if (workOrderId === null) {
    res.status(400).json({ error: 'id must be a positive integer.' });
    return;
  }
  const attachments = await prisma.workOrderAttachment.findMany({
    where: { workOrderId, deletedAt: null },
    include: { uploadedBy: true, deletedBy: true },
    orderBy: { createdAt: 'desc' },
  });
  res.json({ data: attachments.map(serializeAttachment) });
});

app.post(
  '/api/v1/work-orders/:id/attachments',
  requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR),
  express.raw({ type: 'application/octet-stream', limit: MAX_ATTACHMENT_BYTES }),
  async (req: Request, res: Response) => {
    const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
    const originalName = attachmentOriginalName(req.header('x-file-name'));
    const kindHeader = req.header('x-attachment-kind') ?? WorkOrderAttachmentKind.OTHER;
    const mediaType = attachmentMediaType(req.header('x-original-content-type'));
    if (workOrderId === null || !originalName || !isEnumValue(WorkOrderAttachmentKind, kindHeader) || !mediaType) {
      res.status(400).json({ error: 'Provide a valid work-order id, X-File-Name, X-Attachment-Kind, and optional X-Original-Content-Type.' });
      return;
    }
    if (!Buffer.isBuffer(req.body) || req.body.length === 0) {
      res.status(400).json({ error: 'An application/octet-stream attachment body is required.' });
      return;
    }
    if (req.body.length > MAX_ATTACHMENT_BYTES) {
      res.status(413).json({ error: 'Attachments may not exceed 25 MiB.' });
      return;
    }

    const workOrder = await prisma.workOrder.findUnique({ where: { id: workOrderId }, select: { id: true } });
    if (!workOrder) {
      res.status(404).json({ error: 'Work order not found.' });
      return;
    }

    const storageKey = `${workOrderId.toString()}/${randomUUID()}`;
    let storagePath: string;
    try {
      storagePath = attachmentStoragePath(storageKey);
      await mkdir(dirname(storagePath), { recursive: true, mode: 0o700 });
      await writeFile(storagePath, req.body, { flag: 'wx', mode: 0o600 });
    } catch (error) {
      console.error(error);
      res.status(500).json({ error: 'Private attachment storage is unavailable.' });
      return;
    }

    const sha256 = createHash('sha256').update(req.body).digest('hex');
    try {
      const attachment = await prisma.$transaction(async (tx) => {
        const created = await tx.workOrderAttachment.create({
          data: {
            kind: kindHeader,
            originalName,
            mediaType,
            byteSize: req.body.length,
            sha256,
            storageKey,
            workOrderId,
            uploadedById: req.auth!.userId,
          },
          include: { uploadedBy: true, deletedBy: true },
        });
        await writeAuditEvent(tx, req.auth!, 'work_order.attachment_uploaded', 'work_order', workOrderId.toString(), {
          attachment: auditAttachmentSnapshot(created),
        });
        return created;
      });
      res.status(201).json({ data: serializeAttachment(attachment) });
    } catch (error: unknown) {
      await rm(storagePath, { force: true });
      if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2002') {
        res.status(409).json({ error: 'This exact attachment already exists on the work order.' });
        return;
      }
      throw error;
    }
  },
);

app.get('/api/v1/work-orders/:id/attachments/:attachmentId/download', async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const attachmentId = typeof req.params.attachmentId === 'string' ? req.params.attachmentId : '';
  if (workOrderId === null || !/^[0-9a-f-]{36}$/i.test(attachmentId)) {
    res.status(400).json({ error: 'A valid work-order id and attachment id are required.' });
    return;
  }
  const attachment = await prisma.workOrderAttachment.findFirst({
    where: { id: attachmentId, workOrderId, deletedAt: null },
    include: { uploadedBy: true, deletedBy: true },
  });
  if (!attachment) {
    res.status(404).json({ error: 'Attachment not found.' });
    return;
  }
  try {
    const bytes = await readFile(attachmentStoragePath(attachment.storageKey));
    res.setHeader('Content-Disposition', `attachment; filename="${attachment.originalName}"`);
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.type(attachment.mediaType).send(bytes);
  } catch (error) {
    console.error(error);
    res.status(404).json({ error: 'Attachment file is unavailable.' });
  }
});

app.delete('/api/v1/work-orders/:id/attachments/:attachmentId', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN), async (req: Request, res: Response) => {
  const workOrderId = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const attachmentId = typeof req.params.attachmentId === 'string' ? req.params.attachmentId : '';
  if (workOrderId === null || !/^[0-9a-f-]{36}$/i.test(attachmentId)) {
    res.status(400).json({ error: 'A valid work-order id and attachment id are required.' });
    return;
  }
  const attachment = await prisma.$transaction(async (tx) => {
    const existing = await tx.workOrderAttachment.findFirst({
      where: { id: attachmentId, workOrderId, deletedAt: null },
      include: { uploadedBy: true, deletedBy: true },
    });
    if (!existing) return null;
    const deleted = await tx.workOrderAttachment.update({
      where: { id: existing.id },
      data: { deletedAt: new Date(), deletedById: req.auth!.userId },
      include: { uploadedBy: true, deletedBy: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.attachment_deleted', 'work_order', workOrderId.toString(), {
      attachment: auditAttachmentSnapshot(existing),
    });
    return deleted;
  });
  if (!attachment) {
    res.status(404).json({ error: 'Attachment not found.' });
    return;
  }
  res.json({ data: serializeAttachment(attachment) });
});

app.patch('/api/v1/work-orders/:id', requireRoles(OpsUserRole.OWNER, OpsUserRole.ADMIN, OpsUserRole.OPERATOR), async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const body = requestBody(req);
  if (id === null || !body) {
    res.status(400).json({ error: 'A valid work-order id and JSON update object are required.' });
    return;
  }

  const data: Prisma.WorkOrderUpdateInput = {};
  const changedFields: string[] = [];
  let checkInAt: Date | null | undefined;
  let checkOutAt: Date | null | undefined;

  if (hasOwn(body, 'title')) {
    const title = parseNullableText(body.title, 255);
    if (!title) {
      res.status(400).json({ error: 'title must be non-empty and 255 characters or fewer.' });
      return;
    }
    data.title = title;
    changedFields.push('title');
  }
  for (const field of ['scheduledAt', 'checkInAt', 'checkOutAt'] as const) {
    if (!hasOwn(body, field)) continue;
    const value = parseOptionalTimestamp(body[field]);
    if (value === undefined) {
      res.status(400).json({ error: `${field} must be an ISO timestamp with a timezone or null.` });
      return;
    }
    data[field] = value;
    if (field === 'checkInAt') checkInAt = value;
    if (field === 'checkOutAt') checkOutAt = value;
    changedFields.push(field);
  }
  for (const field of ['grossPay', 'mileage'] as const) {
    if (!hasOwn(body, field)) continue;
    const value = parseOptionalMoney(body[field]);
    if (value === undefined) {
      res.status(400).json({ error: `${field} must be a non-negative amount with at most two decimals or null.` });
      return;
    }
    data[field] = value;
    changedFields.push(field);
  }
  for (const field of ['driveMinutes', 'onsiteMinutes', 'adminMinutes'] as const) {
    if (!hasOwn(body, field)) continue;
    const value = parseOptionalMinutes(body[field]);
    if (value === undefined) {
      res.status(400).json({ error: `${field} must be a whole number from 0 through 10080.` });
      return;
    }
    data[field] = value;
    changedFields.push(field);
  }
  if (hasOwn(body, 'notes')) {
    const notes = parseNullableText(body.notes, 10_000);
    if (notes === undefined) {
      res.status(400).json({ error: 'notes must be text or null and 10,000 characters or fewer.' });
      return;
    }
    data.notes = notes;
    changedFields.push('notes');
  }
  if (changedFields.length === 0) {
    res.status(400).json({ error: 'Provide at least one editable work-order field.' });
    return;
  }

  const outcome = await prisma.$transaction(async (tx) => {
    const before = await tx.workOrder.findUnique({ where: { id } });
    if (!before) return { kind: 'not_found' as const };

    const effectiveCheckIn = checkInAt === undefined ? before.checkInAt : checkInAt;
    const effectiveCheckOut = checkOutAt === undefined ? before.checkOutAt : checkOutAt;
    if (effectiveCheckIn && effectiveCheckOut && effectiveCheckOut < effectiveCheckIn) {
      return { kind: 'invalid_times' as const };
    }

    const operationalFields = changedFields.filter((field) => field !== 'notes');
    if ((before.status === WorkOrderStatus.INVOICED || before.status === WorkOrderStatus.PAID) && operationalFields.length > 0) {
      return { kind: 'locked' as const };
    }

    const updated = await tx.workOrder.update({
      where: { id },
      data,
      include: { client: true },
    });
    await writeAuditEvent(tx, req.auth!, 'work_order.updated', 'work_order', updated.id.toString(), {
      before: auditWorkOrderSnapshot(before),
      after: auditWorkOrderSnapshot(updated),
      changedFields,
    });
    return { kind: 'updated' as const, workOrder: updated };
  });

  if (outcome.kind === 'not_found') {
    res.status(404).json({ error: 'Work order not found.' });
    return;
  }
  if (outcome.kind === 'invalid_times') {
    res.status(400).json({ error: 'checkOutAt cannot be earlier than checkInAt.' });
    return;
  }
  if (outcome.kind === 'locked') {
    res.status(409).json({ error: 'Invoiced or paid work orders allow notes only. Use a later adjustment workflow for operational or financial corrections.' });
    return;
  }
  res.json({ data: serializeWorkOrder(outcome.workOrder) });
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
