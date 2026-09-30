import express, { type Express, type Request, type RequestHandler } from 'express';
import { Prisma, PrismaClient, OpsUserRole } from '@prisma/client';
import { createHash, randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile, rm } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { businessToday, dateOnly, maintenanceState, nextDue, number, round, vehicleCost, warrantyState } from './vehicle-model.js';

export function serialized(value: unknown): Prisma.InputJsonValue {
  return JSON.parse(JSON.stringify(value, (_key, item: unknown) => typeof item === 'bigint' ? item.toString() : item)) as Prisma.InputJsonValue;
}
class InputError extends Error { constructor(message: string, public status = 400) { super(message); } }
type FieldValue = string | number | boolean | Date | bigint | null;
type Rule = { type: 'text' | 'decimal' | 'integer' | 'date' | 'boolean' | 'id'; required?: boolean; nullable?: boolean; max?: number; scale?: number; signed?: boolean; values?: readonly string[] };
type Rules = Record<string, Rule>;
const text = (max: number, required = false): Rule => ({ type: 'text', max, required });
const decimal = (scale = 2, signed = false): Rule => ({ type: 'decimal', scale, max: 99999999.99, signed });
const date: Rule = { type: 'date' }, boolean: Rule = { type: 'boolean' }, idRule: Rule = { type: 'id' };
const choice = (values: readonly string[]): Rule => ({ type: 'text', values, max: 64 });
function body(req: Request): Record<string, unknown> {
  if (!req.body || typeof req.body !== 'object' || Array.isArray(req.body)) throw new InputError('A JSON object is required.');
  return req.body as Record<string, unknown>;
}
function id(value: unknown): bigint {
  if (typeof value !== 'string' || !/^[1-9]\d{0,18}$/.test(value)) throw new InputError('Invalid record id.');
  return BigInt(value);
}
// Whitelist fields, normalize human-formatted numbers, then enforce database precision.
function fields(input: Record<string, unknown>, rules: Rules, partial = false): Record<string, FieldValue> {
  const result: Record<string, FieldValue> = {};
  for (const key of Object.keys(input)) if (!(key in rules)) throw new InputError(`Unsupported field: ${key}.`);
  for (const [key, rule] of Object.entries(rules)) {
    const raw = input[key];
    if (raw === undefined) { if (!partial && rule.required) throw new InputError(`${key} is required.`); continue; }
    if (raw === null || raw === '') { if (rule.required || rule.nullable === false || rule.type === 'boolean') throw new InputError(`${key} is required.`); result[key] = null; continue; }
    if (rule.type === 'text') {
      if (typeof raw !== 'string' || raw.trim().length > (rule.max ?? 10000) || (rule.required && !raw.trim()) || (rule.values && !rule.values.includes(raw))) throw new InputError(`${key} is invalid.`);
      result[key] = raw.trim();
    } else if (rule.type === 'boolean') { if (typeof raw !== 'boolean') throw new InputError(`${key} must be true or false.`); result[key] = raw; }
    else if (rule.type === 'date') {
      if (typeof raw !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(raw)) throw new InputError(`${key} must be a calendar date.`);
      const parsed = new Date(raw + 'T00:00:00Z');
      if (!Number.isFinite(parsed.getTime()) || dateOnly(parsed) !== raw || raw < '1900-01-01' || raw > '2199-12-31') throw new InputError(`${key} is not a valid calendar date.`);
      result[key] = parsed;
    } else if (rule.type === 'id') { result[key] = id(String(raw)); }
    else {
      if (typeof raw !== 'string' && typeof raw !== 'number') throw new InputError(`${key} must be numeric.`);
      const normalized = String(raw).replace(/,/g, '').trim();
      const pattern = new RegExp(`^${rule.signed ? '-?' : ''}\\d+(?:\\.\\d{1,${rule.scale ?? 0}})?$`);
      if ((rule.type === 'integer' ? !/^\d+$/.test(normalized) : !pattern.test(normalized)) || Math.abs(Number(normalized)) > (rule.max ?? 100000)) throw new InputError(`${key} exceeds its supported range or precision.`);
      result[key] = rule.type === 'integer' ? Number(normalized) : normalized;
    }
  }
  return result;
}
const vehicleRules: Rules = {
  name: text(160, true), modelYear: { type: 'integer', max: 2199 }, make: text(100), model: text(140), trimName: text(140), vin: text(64), plate: text(40), inServiceDate: date, outOfServiceDate: date, currentOdometer: decimal(), acquisitionCost: decimal(), expectedResidualValue: decimal(), expectedServiceMiles: decimal(), fuelMpgEstimate: { ...decimal(), max: 999999.99 }, fuelPricePerGallonEstimate: { ...decimal(4), max: 999999.9999 }, maintenanceReservePerMile: { ...decimal(4), max: 999999.9999 }, tireReservePerMile: { ...decimal(4), max: 999999.9999 }, repairReservePerMile: { ...decimal(4), max: 999999.9999 }, depreciationPerMileOverride: { ...decimal(4), max: 999999.9999 }, insuranceAnnualCost: decimal(), registrationAnnualCost: decimal(), otherFixedAnnualCost: decimal(), expectedAnnualBusinessMiles: decimal(), active: boolean, notes: text(20000),
};
for (const key of ['acquisitionCost','expectedResidualValue','expectedServiceMiles','fuelMpgEstimate','fuelPricePerGallonEstimate','maintenanceReservePerMile','tireReservePerMile','repairReservePerMile','insuranceAnnualCost','registrationAnnualCost','otherFixedAnnualCost','expectedAnnualBusinessMiles']) vehicleRules[key].nullable=false;
const eventTypes = ['FUEL','OIL_CHANGE','ROUTINE_MAINTENANCE','TIRE','BRAKE','REPAIR','BREAKDOWN','ENGINE','TRANSMISSION','INSURANCE','REGISTRATION','CAR_WASH','TOLL','PARKING','OTHER'];
const eventRules: Rules = { eventType: { ...choice(eventTypes), required: true }, costTreatment: choice(['NORMAL','FIXED','EXTRAORDINARY','AMORTIZED','DIRECT_TRIP']), eventDate: { ...date, required: true }, odometer: decimal(), vendor: text(180), description: text(255, true), amount: decimal(2, true), gallons: { ...decimal(3), max: 9999999.999 }, fuelPricePerGallon: { ...decimal(4), max: 999999.9999 }, amortizeOverMiles: decimal(), verificationStatus: choice(['RECORDED','PENDING_CONFIRMATION']), notes: text(20000) };
const componentRules: Rules = { componentType: text(64, true), name: text(160, true), status: choice(['UNKNOWN','BASELINE_NEEDED','RESET','SERVICED','INSPECTED','WATCH','FAILED','RETIRED']), baselineDate: date, baselineOdometer: decimal(), baselineEventId: idRule, warrantyUntilDate: date, warrantyUntilMiles: decimal(), warrantyMileageKind: choice(['UNCONFIRMED','UNLIMITED','SINCE_BASELINE','ABSOLUTE_ODOMETER']), warrantyRequirements: text(20000), notes: text(20000) };
const maintenanceRules: Rules = { componentId: idRule, name: text(190, true), subsystem: text(120), scheduleSource: choice(['MANUFACTURER','WARRANTY','INSPECTION','MANUAL','SHOP_RECOMMENDED']), clockType: choice(['VEHICLE','ENGINE','COMPONENT','CALENDAR']), intervalMiles: decimal(), intervalMonths: { type: 'integer', max: 1200 }, baselineOdometer: decimal(), baselineDate: date, lastEventId: idRule, estimatedServiceCost: decimal(), active: boolean, notes: text(20000) };
maintenanceRules.estimatedServiceCost.nullable=false;
for(const [rules,keys] of [[eventRules,['costTreatment','verificationStatus']], [componentRules,['status','warrantyMileageKind']], [maintenanceRules,['scheduleSource','clockType']]] as [Rules,string[]][]) for(const key of keys)rules[key].nullable=false;
const receiptRules: Rules = { category: choice(['FUEL','MAINTENANCE','REPAIR','PARTS','TOOLS','SUPPLIES','JOB_MATERIALS','EQUIPMENT','WARRANTY','BUSINESS_EXPENSE','OTHER']), receiptDate: date, vendor: text(180), amount: decimal(2,true), gallons: { ...decimal(3), max: 9999999.999 }, fuelPricePerGallon: { ...decimal(4), max: 999999.9999 }, odometer: decimal(), notes: text(20000) };
receiptRules.category.nullable=false;
async function audit(tx: Prisma.TransactionClient, req: Request, action: string, subjectId: bigint | string, before: unknown, after: unknown) {
  await tx.opsAuditEvent.create({ data: { actorUserId: req.auth!.userId, action: `vehicle.${action}`, subjectType: 'vehicle', subjectId: String(subjectId), details: serialized({ before, after }) } });
}
async function lockedVehicle(tx: Prisma.TransactionClient, vehicleId: bigint) {
  await tx.$queryRaw`SELECT id FROM Vehicle WHERE id = ${vehicleId} FOR UPDATE`;
  const row = await tx.vehicle.findUnique({ where: { id: vehicleId } });
  if (!row) throw new InputError('Vehicle not found.',404);
  return row;
}
async function eventReference(tx: Prisma.TransactionClient, vehicleId: bigint, eventId: bigint | null | undefined) {
  if (eventId === undefined || eventId === null) return null;
  const event = await tx.vehicleEvent.findFirst({ where: { id: eventId, vehicleId, deletedAt: null } });
  if (!event) throw new InputError('Service event must belong to this vehicle and be active.');
  return event;
}
function baselineChanged(data: Record<string, unknown>, before: { baselineDate: Date | null; baselineOdometer: Prisma.Decimal | null }) {
  return ('baselineDate' in data && dateOnly(data.baselineDate as Date|null) !== dateOnly(before.baselineDate)) || ('baselineOdometer' in data && (data.baselineOdometer == null ? before.baselineOdometer !== null : before.baselineOdometer === null || !before.baselineOdometer.eq(String(data.baselineOdometer))));
}
function matchingBaseline(event: { eventDate: Date; odometer: Prisma.Decimal | null }, baselineDate: Date | null | undefined, baselineOdometer: string | Prisma.Decimal | null | undefined) {
  if (dateOnly(event.eventDate) !== dateOnly(baselineDate ?? null) || (event.odometer === null) !== (baselineOdometer == null) || (event.odometer !== null && baselineOdometer != null && !event.odometer.eq(baselineOdometer))) throw new InputError('The baseline date and odometer must match the selected service event.');
}
export async function scoringVehicle(prisma: PrismaClient) {
  const settings = await prisma.vehicleSettings.findUnique({ where: { id: 1 }, include: { defaultVehicle: { include: { events: true } } } });
  const vehicle = settings?.defaultVehicle;
  if (!vehicle?.active) return undefined;
  const cost = vehicleCost(vehicle, vehicle.events);
  return { vehicleId: vehicle.id.toString(), vehicleName: vehicle.name, rate: cost.allIn, complete: cost.complete, model: cost };
}
function storageRoot() { return resolve(process.env.V2_VEHICLE_STORAGE_ROOT || '/var/lib/mmit-ops/private/vehicle-documents'); }
function localPath(key: string) { if (!/^[0-9a-f-]{36}\.(?:pdf|png|jpg|webp)$/.test(key)) throw new InputError('Invalid document storage key.'); return join(storageRoot(), key); }
function mime(bytes: Buffer) {
  if (bytes.subarray(0,5).equals(Buffer.from('%PDF-'))) return { type: 'application/pdf', ext: 'pdf' };
  if (bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10]))) return { type: 'image/png', ext: 'png' };
  if (bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255) return { type: 'image/jpeg', ext: 'jpg' };
  if (bytes.subarray(0,4).toString() === 'RIFF' && bytes.subarray(8,12).toString() === 'WEBP') return { type: 'image/webp', ext: 'webp' };
  throw new InputError('Upload a PDF, PNG, JPEG, or WebP document.');
}
export function registerVehicleRoutes(app: Express, prisma: PrismaClient, requireAuth: RequestHandler) {
  const router = express.Router(); router.use(requireAuth);
  const writeRoles: readonly OpsUserRole[] = [OpsUserRole.OWNER,OpsUserRole.ADMIN,OpsUserRole.OPERATOR];
  router.use((req,res,next) => { if (!['GET','HEAD','OPTIONS'].includes(req.method) && !writeRoles.includes(req.auth!.role)) { res.status(403).json({ error: 'Your role cannot change vehicle records.' }); return; } next(); });
  router.get('/', async (_req,res) => {
    const [vehicles,settings] = await Promise.all([prisma.vehicle.findMany({ include: { events: true, maintenance: true }, orderBy: { id: 'asc' } }),prisma.vehicleSettings.findUnique({ where: { id: 1 } })]);
    res.json({ data: vehicles.map(v => serialized({ ...v, events: undefined, maintenance: undefined, isPrimary: settings?.defaultVehicleId === v.id, cost: vehicleCost(v,v.events), maintenanceAlerts: v.maintenance.filter(m => !['CURRENT','DISABLED'].includes(maintenanceState(m,v.currentOdometer))).length })) });
  });
  router.get('/:vehicleId', async (req,res) => {
    const vehicleId = id(req.params.vehicleId), today = businessToday();
    const vehicle = await prisma.vehicle.findUnique({ where: { id: vehicleId }, include: { events: { orderBy: [{eventDate:'desc'},{id:'desc'}] }, components: {orderBy:{id:'asc'}}, maintenance: {orderBy:{id:'asc'}}, receipts: {orderBy:{id:'desc'},include:{expenseDraft:true}}, documents: true } });
    if (!vehicle) throw new InputError('Vehicle not found.',404);
    const settings = await prisma.vehicleSettings.findUnique({where:{id:1}});
    const spend = vehicle.events.filter(e=>!e.deletedAt && dateOnly(e.eventDate)!.startsWith(today.slice(0,4))).reduce((sum,e)=>sum+number(e.amount),0);
    res.json({ data: serialized({ ...vehicle, isPrimary: settings?.defaultVehicleId === vehicle.id, cost: vehicleCost(vehicle,vehicle.events,today), annualSpend:round(spend,2), components: vehicle.components.map(c=>({...c,warranty:warrantyState(c,vehicle.currentOdometer,today)})), maintenance:vehicle.maintenance.map(m=>({...m,status:maintenanceState(m,vehicle.currentOdometer,today), baselineVerification:vehicle.events.find(e=>e.id===m.lastEventId)?.verificationStatus ?? 'RECORDED', baselineEvidenceMissing:Boolean(m.lastEventId && !vehicle.events.some(e=>e.id===m.lastEventId&&!e.deletedAt))})), scheduledReserve: round(vehicle.maintenance.filter(m=>m.active&&!m.deletedAt&&number(m.intervalMiles)>0).reduce((sum,m)=>sum+number(m.estimatedServiceCost)/number(m.intervalMiles),0)) }) });
  });
  router.get('/:vehicleId/export', async (req,res) => {
    const vehicle = await prisma.vehicle.findUnique({ where:{id:id(req.params.vehicleId)},include:{ events:{include:{documents:true}},components:{include:{documents:true}},maintenance:{include:{documents:true}},receipts:{include:{documents:true,expenseDraft:true}},documents:true } });
    if (!vehicle) throw new InputError('Vehicle not found.',404);
    res.attachment(`vehicle-${vehicle.id}-history.json`).json(serialized({ exportedAt:new Date().toISOString(), vehicle }));
  });
  router.post('/',async(req,res)=> {
    const data = fields(body(req),vehicleRules) as unknown as Prisma.VehicleUncheckedCreateInput;
    const row = await prisma.$transaction(async tx=>{const created=await tx.vehicle.create({data});await audit(tx,req,'created',created.id,null,created);return created;});
    res.status(201).json({data:serialized(row)});
  });
  router.patch('/:vehicleId',async(req,res)=> {
    const vehicleId=id(req.params.vehicleId), data=fields(body(req),vehicleRules,true) as Prisma.VehicleUncheckedUpdateInput;
    const row=await prisma.$transaction(async tx=>{const before=await lockedVehicle(tx,vehicleId);const updated=await tx.vehicle.update({where:{id:vehicleId},data});await audit(tx,req,'updated',vehicleId,before,updated);return updated;});res.json({data:serialized(row)});
  });
  router.post('/:vehicleId/primary',async(req,res)=> {
    const vehicleId=id(req.params.vehicleId);
    await prisma.$transaction(async tx=>{await tx.vehicleSettings.upsert({where:{id:1},create:{id:1},update:{}});await tx.$queryRaw`SELECT id FROM VehicleSettings WHERE id=1 FOR UPDATE`;const before=await tx.vehicleSettings.findUnique({where:{id:1}});const vehicle=await lockedVehicle(tx,vehicleId);if(!vehicle.active)throw new InputError('Reactivate this vehicle before making it primary.');const after=await tx.vehicleSettings.update({where:{id:1},data:{defaultVehicleId:vehicleId}});await audit(tx,req,'primary_changed',vehicleId,before,after);});res.json({data:{vehicleId:String(vehicleId)}});
  });
  router.post('/:vehicleId/events',async(req,res)=> {
    const vehicleId=id(req.params.vehicleId), input=body(req), maintenanceIds=input.maintenanceIds;
    const eventBody={...input};delete eventBody.maintenanceIds;
    const data=fields(eventBody,eventRules) as unknown as Omit<Prisma.VehicleEventUncheckedCreateInput,'vehicleId'>;
    if(maintenanceIds!==undefined && (!Array.isArray(maintenanceIds)||maintenanceIds.length>50))throw new InputError('maintenanceIds must be a list of at most 50 schedule ids.');
    const selected=Array.isArray(maintenanceIds)?[...new Set(maintenanceIds.map(value=>id(String(value))))]:[];
    const row=await prisma.$transaction(async tx=> {
      const vehicle=await lockedVehicle(tx,vehicleId);
      const items=await tx.vehicleMaintenanceItem.findMany({where:{id:{in:selected},vehicleId,deletedAt:null,active:true}});
      if(items.length!==selected.length)throw new InputError('Maintenance schedules must belong to this vehicle and be active.');
      if(items.some(m=>number(m.intervalMiles)>0)&&data.odometer==null)throw new InputError('An odometer reading is required for mileage-based maintenance.');
      const created=await tx.vehicleEvent.create({data:{...data,vehicleId}});
      for(const item of items){const due=nextDue(created.odometer,created.eventDate,item.intervalMiles,item.intervalMonths);const after=await tx.vehicleMaintenanceItem.update({where:{id:item.id},data:{baselineDate:created.eventDate,baselineOdometer:created.odometer,lastEventId:created.id,nextDueDate:due.nextDueDate,nextDueOdometer:due.nextDueOdometer}});await audit(tx,req,'maintenance_serviced',vehicleId,item,after);}
      if(created.verificationStatus==='RECORDED'&&created.odometer!==null&&(vehicle.currentOdometer===null||created.odometer.gt(vehicle.currentOdometer)))await tx.vehicle.update({where:{id:vehicleId},data:{currentOdometer:created.odometer}});
      await audit(tx,req,'event_created',vehicleId,null,created);return created;
    });res.status(201).json({data:serialized(row)});
  });
  router.patch('/:vehicleId/events/:recordId',async(req,res)=> {
    const vehicleId=id(req.params.vehicleId), recordId=id(req.params.recordId), data=fields(body(req),eventRules,true) as Prisma.VehicleEventUncheckedUpdateInput;
    const row=await prisma.$transaction(async tx=>{const vehicle=await lockedVehicle(tx,vehicleId);const before=await tx.vehicleEvent.findFirst({where:{id:recordId,vehicleId,deletedAt:null}});if(!before)throw new InputError('Service event not found.',404);
      if(('eventDate'in data&&dateOnly(data.eventDate as Date|null)!==dateOnly(before.eventDate))||('odometer'in data&&(data.odometer===null?before.odometer!==null:before.odometer===null||!before.odometer.eq(String(data.odometer))))){const linked=await tx.vehicleMaintenanceItem.count({where:{lastEventId:recordId}})+await tx.vehicleComponent.count({where:{baselineEventId:recordId}});if(linked)throw new InputError('This event anchors a maintenance baseline. Record a correcting service event to preserve the history.',409);}
      const after=await tx.vehicleEvent.update({where:{id:recordId},data});if(after.verificationStatus==='RECORDED'&&after.odometer!==null&&(vehicle.currentOdometer===null||after.odometer.gt(vehicle.currentOdometer)))await tx.vehicle.update({where:{id:vehicleId},data:{currentOdometer:after.odometer}});await audit(tx,req,'event_updated',vehicleId,before,after);return after;});res.json({data:serialized(row)});
  });
  router.post('/:vehicleId/components',async(req,res)=> {
    const vehicleId=id(req.params.vehicleId);const data=fields(body(req),componentRules) as unknown as Omit<Prisma.VehicleComponentUncheckedCreateInput,'vehicleId'>;
    if(data.warrantyMileageKind==='UNLIMITED')data.warrantyUntilMiles=null;
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const evidence=await eventReference(tx,vehicleId,data.baselineEventId as bigint|null|undefined);if(evidence)matchingBaseline(evidence,data.baselineDate as Date|null|undefined,data.baselineOdometer as string|null|undefined);const created=await tx.vehicleComponent.create({data:{...data,vehicleId}});await audit(tx,req,'component_created',vehicleId,null,created);return created;});res.status(201).json({data:serialized(row)});
  });
  router.patch('/:vehicleId/components/:recordId',async(req,res)=> {
    const vehicleId=id(req.params.vehicleId),recordId=id(req.params.recordId);const data=fields(body(req),componentRules,true) as Prisma.VehicleComponentUncheckedUpdateInput;
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const before=await tx.vehicleComponent.findFirst({where:{id:recordId,vehicleId,deletedAt:null}});if(!before)throw new InputError('Component not found.',404);if(baselineChanged(data,before)&&data.baselineEventId===undefined)data.baselineEventId=null;const evidence=await eventReference(tx,vehicleId,data.baselineEventId as bigint|null|undefined);if(evidence)matchingBaseline(evidence,(data.baselineDate===undefined?before.baselineDate:data.baselineDate) as Date|null,(data.baselineOdometer===undefined?before.baselineOdometer:data.baselineOdometer) as string|Prisma.Decimal|null);if((data.warrantyMileageKind??before.warrantyMileageKind)==='UNLIMITED')data.warrantyUntilMiles=null;const after=await tx.vehicleComponent.update({where:{id:recordId},data});await audit(tx,req,'component_updated',vehicleId,before,after);return after;});res.json({data:serialized(row)});
  });
  async function maintenanceData(tx:Prisma.TransactionClient,vehicleId:bigint,data:Record<string,FieldValue>,before?:Prisma.VehicleMaintenanceItemGetPayload<{}>){
    const componentId=data.componentId===undefined?before?.componentId:data.componentId;
    if(componentId!=null&&!await tx.vehicleComponent.findFirst({where:{id:componentId as bigint,vehicleId,deletedAt:null}}))throw new InputError('Component must belong to this vehicle.');
    if(before&&baselineChanged(data,before)&&data.lastEventId===undefined)data.lastEventId=null;
    const evidence=await eventReference(tx,vehicleId,data.lastEventId as bigint|null|undefined);
    const baselineOdometer=data.baselineOdometer===undefined?before?.baselineOdometer??null:data.baselineOdometer as string|null;
    const baselineDate=data.baselineDate===undefined?before?.baselineDate??null:data.baselineDate as Date|null;
    const intervalMiles=data.intervalMiles===undefined?before?.intervalMiles??null:data.intervalMiles as string|null;
    const intervalMonths=data.intervalMonths===undefined?before?.intervalMonths??null:data.intervalMonths as number|null;
    if(evidence)matchingBaseline(evidence,baselineDate,baselineOdometer);
    if((data.clockType??before?.clockType)==='CALENDAR'&&number(intervalMiles)>0)throw new InputError('Calendar-only schedules must not include a mileage interval.');
    return {...data,...nextDue(baselineOdometer,baselineDate,intervalMiles,intervalMonths)};
  }
  router.post('/:vehicleId/maintenance',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),data=fields(body(req),maintenanceRules);
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const parsed=await maintenanceData(tx,vehicleId,data);const created=await tx.vehicleMaintenanceItem.create({data:{...parsed,vehicleId} as unknown as Prisma.VehicleMaintenanceItemUncheckedCreateInput});await audit(tx,req,'maintenance_created',vehicleId,null,created);return created;});res.status(201).json({data:serialized(row)});
  });
  router.patch('/:vehicleId/maintenance/:recordId',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),recordId=id(req.params.recordId),data=fields(body(req),maintenanceRules,true);
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const before=await tx.vehicleMaintenanceItem.findFirst({where:{id:recordId,vehicleId,deletedAt:null}});if(!before)throw new InputError('Maintenance schedule not found.',404);const parsed=await maintenanceData(tx,vehicleId,data,before);const after=await tx.vehicleMaintenanceItem.update({where:{id:recordId},data:parsed as Prisma.VehicleMaintenanceItemUncheckedUpdateInput});await audit(tx,req,'maintenance_updated',vehicleId,before,after);return after;});res.json({data:serialized(row)});
  });
  router.post('/:vehicleId/receipts',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),data={...fields(body(req),receiptRules),vehicleId};
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const created=await tx.vehicleReceipt.create({data});await audit(tx,req,'receipt_created',vehicleId,null,created);return created;});res.status(201).json({data:serialized(row)});
  });
  router.patch('/:vehicleId/receipts/:recordId',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),recordId=id(req.params.recordId),data=fields(body(req),receiptRules,true) as Prisma.VehicleReceiptUncheckedUpdateInput;
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const before=await tx.vehicleReceipt.findFirst({where:{id:recordId,vehicleId,deletedAt:null}});if(!before)throw new InputError('Receipt not found.',404);if(before.eventId||await tx.vehicleExpenseDraft.findUnique({where:{receiptId:recordId}}))throw new InputError('This receipt is already linked to an event or expense draft. Correct the linked record to retain the evidence trail.',409);const after=await tx.vehicleReceipt.update({where:{id:recordId},data:{...data,status:'REVIEWED'}});await audit(tx,req,'receipt_reviewed',vehicleId,before,after);return after;});res.json({data:serialized(row)});
  });
  router.post('/:vehicleId/receipts/:recordId/route',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),recordId=id(req.params.recordId);const input=fields(body(req),{target:{...choice(['UNROUTED','VEHICLE_EVENT','BUSINESS_EXPENSE','FIELD_OPS_EXPENSE','EQUIPMENT_ASSET','TOOL_ASSET','RECEIPT_ONLY','IGNORE_PERSONAL']),required:true},eventId:idRule,eventType:choice(eventTypes),costTreatment:choice(['NORMAL','FIXED','EXTRAORDINARY','AMORTIZED','DIRECT_TRIP']),notes:text(20000)});
    const row=await prisma.$transaction(async tx=>{
      const vehicle=await lockedVehicle(tx,vehicleId);const before=await tx.vehicleReceipt.findFirst({where:{id:recordId,vehicleId,deletedAt:null},include:{documents:true,expenseDraft:true}});if(!before)throw new InputError('Receipt not found.',404);
      if(before.eventId||before.expenseDraft)throw new InputError('This receipt is already routed. Its existing link is protected against duplicate expenses.',409);
      let eventId:bigint|null=null;
      if(input.target==='VEHICLE_EVENT'){
        if(input.eventId){eventId=input.eventId as bigint;await eventReference(tx,vehicleId,eventId);}
        else {if(!before.receiptDate)throw new InputError('Review the receipt date before creating a service event.');const created=await tx.vehicleEvent.create({data:{vehicleId,eventType:String(input.eventType??(before.category==='FUEL'?'FUEL':'OTHER')),costTreatment:String(input.costTreatment??'NORMAL'),eventDate:before.receiptDate,odometer:before.odometer,vendor:before.vendor,description:`${before.category} receipt`,amount:before.amount,gallons:before.gallons,fuelPricePerGallon:before.fuelPricePerGallon,notes:before.notes}});eventId=created.id;if(created.odometer!==null&&(vehicle.currentOdometer===null||created.odometer.gt(vehicle.currentOdometer)))await tx.vehicle.update({where:{id:vehicleId},data:{currentOdometer:created.odometer}});await audit(tx,req,'receipt_event_created',vehicleId,null,created);}
      }else if(['BUSINESS_EXPENSE','FIELD_OPS_EXPENSE','EQUIPMENT_ASSET','TOOL_ASSET'].includes(String(input.target))){
        if(before.amount===null)throw new InputError('Review the receipt amount before creating an expense draft.');
        await tx.vehicleExpenseDraft.create({data:{receiptId:recordId,expenseDate:before.receiptDate,vendor:before.vendor,amount:before.amount,category:before.category,description:`${before.category} receipt`,notes:before.notes}});
      }
      const after=await tx.vehicleReceipt.update({where:{id:recordId},data:{eventId,routeTarget:String(input.target),routeStatus:input.target==='IGNORE_PERSONAL'?'IGNORED':input.target==='UNROUTED'?'UNROUTED':eventId?'LINKED':'REVIEWED',status:eventId?'LINKED':'REVIEWED',routedAt:new Date(),routeNotes:input.notes as string|null|undefined}});
      await audit(tx,req,'receipt_routed',vehicleId,before,after);return after;
    });res.json({data:serialized(row)});
  });
  router.post('/:vehicleId/expense-drafts/:recordId/status',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),recordId=id(req.params.recordId),input=fields(body(req),{status:{...choice(['DRAFT','READY','VOID']),required:true}});
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const before=await tx.vehicleExpenseDraft.findFirst({where:{id:recordId,receipt:{vehicleId}}});if(!before)throw new InputError('Expense draft not found.',404);if(before.status==='EXPORTED')throw new InputError('An exported expense needs an accounting adjustment; its existing posting is protected.',409);const after=await tx.vehicleExpenseDraft.update({where:{id:recordId},data:{status:String(input.status)}});await audit(tx,req,'expense_draft_status',vehicleId,before,after);return after;});res.json({data:serialized(row)});
  });
  router.post('/:vehicleId/documents',express.raw({type:'application/octet-stream',limit:'15mb'}),async(req,res)=>{
    const vehicleId=id(req.params.vehicleId);const bytes=req.body;
    if(!Buffer.isBuffer(bytes)||bytes.length===0)throw new InputError('Document bytes are required.');
    const kind=mime(bytes);let decodedName:string;try{decodedName=decodeURIComponent(req.header('x-file-name')??'');}catch{throw new InputError('Invalid filename encoding.');}const originalName=decodedName.replace(/[\r\n\x00-\x1f]/g,'').split(/[\\/]/).pop()??'';
    if(!originalName||originalName.length>255)throw new InputError('A filename of at most 255 characters is required.');
    const references:{eventId?:bigint;componentId?:bigint;maintenanceId?:bigint;receiptId?:bigint}={};
    for(const field of ['eventId','componentId','maintenanceId','receiptId'] as const){const value=req.query[field];if(value!==undefined)references[field]=id(value);}
    const storageKey=`${randomUUID()}.${kind.ext}`;await mkdir(storageRoot(),{recursive:true,mode:0o700});await writeFile(localPath(storageKey),bytes,{flag:'wx',mode:0o600});
    try{const row=await prisma.$transaction(async tx=>{
      await lockedVehicle(tx,vehicleId);await eventReference(tx,vehicleId,references.eventId);
      if(references.componentId&&!await tx.vehicleComponent.findFirst({where:{id:references.componentId,vehicleId,deletedAt:null}}))throw new InputError('Component is not on this vehicle.');
      if(references.maintenanceId&&!await tx.vehicleMaintenanceItem.findFirst({where:{id:references.maintenanceId,vehicleId,deletedAt:null}}))throw new InputError('Maintenance schedule is not on this vehicle.');
      if(references.receiptId&&!await tx.vehicleReceipt.findFirst({where:{id:references.receiptId,vehicleId,deletedAt:null}}))throw new InputError('Receipt is not on this vehicle.');
      const created=await tx.vehicleDocument.create({data:{vehicleId,...references,originalName,mimeType:kind.type,byteSize:BigInt(bytes.length),sha256:createHash('sha256').update(bytes).digest('hex'),storageKey}});await audit(tx,req,'document_uploaded',vehicleId,null,{id:created.id,originalName,byteSize:bytes.length,sha256:created.sha256,...references});return created;
    });res.status(201).json({data:serialized(row)});}catch(error){await rm(localPath(storageKey),{force:true});throw error;}
  });
  router.get('/:vehicleId/documents/:documentId',async(req,res)=>{
    const document=await prisma.vehicleDocument.findFirst({where:{id:String(req.params.documentId),vehicleId:id(req.params.vehicleId),deletedAt:null}});if(!document)throw new InputError('Document not found.',404);
    if(!document.storageKey)throw new InputError('This historical document is stored in V1 or OneDrive. Open its preserved OneDrive link.',409);
    const bytes=await readFile(localPath(document.storageKey));if(document.sha256&&createHash('sha256').update(bytes).digest('hex')!==document.sha256)throw new InputError('Document integrity check failed.',409);
    res.setHeader('X-Content-Type-Options','nosniff');res.setHeader('Cache-Control','private, no-store');res.attachment(document.originalName).type(document.mimeType??'application/octet-stream').send(bytes);
  });
  router.post('/:vehicleId/documents/:documentId/archive',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),input=fields(body(req),{archived:{type:'boolean',required:true}});
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);const before=await tx.vehicleDocument.findFirst({where:{id:String(req.params.documentId),vehicleId}});if(!before)throw new InputError('Document not found.',404);const after=await tx.vehicleDocument.update({where:{id:before.id},data:{deletedAt:input.archived?new Date():null}});await audit(tx,req,'document_archived',vehicleId,before,after);return after;});res.json({data:serialized(row)});
  });
  // Archive and restore preserve the source record and audit trail; evidence is never erased.
  router.post('/:vehicleId/:kind/:recordId/archive',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),recordId=id(req.params.recordId),kind=String(req.params.kind);const input=fields(body(req),{archived:{type:'boolean',required:true}}),deletedAt=input.archived?new Date():null;
    const row=await prisma.$transaction(async tx=>{await lockedVehicle(tx,vehicleId);
      if(kind==='events'){const before=await tx.vehicleEvent.findFirst({where:{id:recordId,vehicleId}});if(!before)throw new InputError('Event not found.',404);const links=await tx.vehicleMaintenanceItem.count({where:{lastEventId:recordId,deletedAt:null}})+await tx.vehicleComponent.count({where:{baselineEventId:recordId,deletedAt:null}});if(deletedAt&&links)throw new InputError('This event anchors a baseline. Record a replacement baseline before archiving it.',409);const after=await tx.vehicleEvent.update({where:{id:recordId},data:{deletedAt}});await audit(tx,req,'event_archived',vehicleId,before,after);return after;}
      if(kind==='components'){const before=await tx.vehicleComponent.findFirst({where:{id:recordId,vehicleId}});if(!before)throw new InputError('Component not found.',404);const after=await tx.vehicleComponent.update({where:{id:recordId},data:{deletedAt}});await audit(tx,req,'component_archived',vehicleId,before,after);return after;}
      if(kind==='maintenance'){const before=await tx.vehicleMaintenanceItem.findFirst({where:{id:recordId,vehicleId}});if(!before)throw new InputError('Schedule not found.',404);const after=await tx.vehicleMaintenanceItem.update({where:{id:recordId},data:{deletedAt}});await audit(tx,req,'maintenance_archived',vehicleId,before,after);return after;}
      if(kind==='receipts'){const before=await tx.vehicleReceipt.findFirst({where:{id:recordId,vehicleId}});if(!before)throw new InputError('Receipt not found.',404);const after=await tx.vehicleReceipt.update({where:{id:recordId},data:{deletedAt}});await audit(tx,req,'receipt_archived',vehicleId,before,after);return after;}
      throw new InputError('Unsupported archive record type.');
    });res.json({data:serialized(row)});
  });
  router.get('/:vehicleId/audit',async(req,res)=>{const vehicleId=id(req.params.vehicleId);const rows=await prisma.opsAuditEvent.findMany({where:{subjectType:'vehicle',subjectId:String(vehicleId)},orderBy:{id:'desc'},take:200});res.json({data:serialized(rows)});});
  router.post('/:vehicleId/work-orders/:workOrderId',async(req,res)=>{
    const vehicleId=id(req.params.vehicleId),workOrderId=id(req.params.workOrderId);
    const row=await prisma.$transaction(async tx=>{const vehicle=await lockedVehicle(tx,vehicleId);if(!vehicle.active)throw new InputError('Vehicle must be active.');const before=await tx.workOrder.findUnique({where:{id:workOrderId}});if(!before)throw new InputError('Work order not found.',404);if(['INVOICED','PAID'].includes(before.status))throw new InputError('Invoiced or paid work orders require an adjustment workflow.',409);const after=await tx.workOrder.update({where:{id:workOrderId},data:{vehicleId,vehicleCostSnapshot:serialized(vehicleCost(vehicle,await tx.vehicleEvent.findMany({where:{vehicleId}})))}});await audit(tx,req,'work_order_assigned',vehicleId,{workOrderId:String(workOrderId),vehicleId:before.vehicleId},{workOrderId:String(workOrderId),vehicleId});return after;});res.json({data:serialized(row)});
  });
  router.use((error:unknown,_req:Request,res:express.Response,next:express.NextFunction)=>{if(error instanceof InputError){res.status(error.status).json({error:error.message});return;}next(error);});
  app.use('/api/v1/vehicles',router);
}
