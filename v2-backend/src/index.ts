import express, { Request, Response } from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import { Prisma, PrismaClient, WorkOrderSource, WorkOrderStatus } from '@prisma/client';

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

type WorkOrderWithClient = Prisma.WorkOrderGetPayload<{ include: { client: true } }>;

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
