import express, { Request, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import {
  ClientStatus,
  Prisma,
  PrismaClient,
  ServiceTier,
  WorkOrderSource,
  WorkOrderStatus,
} from '@prisma/client';

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

app.post('/api/v1/clients', async (req: Request, res: Response) => {
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
    const client = await prisma.client.create({
      data: { name, email, phone, syncroCustomerId, notes, status, serviceTier },
      include: { _count: { select: { workOrders: true } } },
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

app.patch('/api/v1/clients/:id', async (req: Request, res: Response) => {
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
    const client = await prisma.client.update({
      where: { id },
      data,
      include: { _count: { select: { workOrders: true } } },
    });
    res.json({ data: serializeClient(client) });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error) {
      if (error.code === 'P2025') {
        res.status(404).json({ error: 'Client not found.' });
        return;
      }
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

app.post('/api/v1/work-orders', async (req: Request, res: Response) => {
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
    const workOrder = await prisma.workOrder.create({
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

app.patch('/api/v1/work-orders/:id/client', async (req: Request, res: Response) => {
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
    const workOrder = await prisma.workOrder.update({
      where: { id },
      data: { clientId },
      include: { client: true },
    });
    res.json({ data: serializeWorkOrder(workOrder) });
  } catch (error: unknown) {
    if (typeof error === 'object' && error !== null && 'code' in error && error.code === 'P2025') {
      res.status(404).json({ error: 'Work order not found.' });
      return;
    }
    throw error;
  }
});

app.patch('/api/v1/work-orders/:id/status', async (req: Request, res: Response) => {
  const id = typeof req.params.id === 'string' ? parseId(req.params.id) : null;
  const nextStatus = req.body?.status;
  if (id === null || !isEnumValue(WorkOrderStatus, nextStatus)) {
    res.status(400).json({ error: 'A valid work-order id and status are required.' });
    return;
  }
  const workOrder = await prisma.workOrder.findUnique({ where: { id }, include: { client: true } });
  if (!workOrder) {
    res.status(404).json({ error: 'Work order not found.' });
    return;
  }
  if (!lifecycle[workOrder.status].includes(nextStatus)) {
    res.status(409).json({ error: `Cannot move ${workOrder.status} directly to ${nextStatus}.` });
    return;
  }
  const updated = await prisma.workOrder.update({
    where: { id },
    data: { status: nextStatus },
    include: { client: true },
  });
  res.json({ data: serializeWorkOrder(updated) });
});

app.use((error: unknown, _req: Request, res: Response, _next: express.NextFunction) => {
  console.error(error);
  res.status(500).json({ error: 'Unexpected server error.' });
});

// Start the server listening process
app.listen(PORT, () => {
  console.log(`🚀 Modernized backend running smoothly on port ${PORT}`);
});
