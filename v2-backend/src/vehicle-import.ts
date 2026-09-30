import { Prisma, PrismaClient } from '@prisma/client';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { vehicleCost, nextDue } from './vehicle-model.js';
import { serialized } from './vehicles.js';
type Row = Record<string, string | null>;
export type VehicleSnapshot = { database: string; exportedAt: string; tables: Record<string, { schema: string; rows: Row[] }> };
const day = (value: string | null): Date | null => value ? new Date(value.slice(0,10)+'T00:00:00Z') : null;
const timestamp = (value: string | null): Date | null => value ? new Date(value.replace(' ','T')+'Z') : null;
function required(value:string|null|undefined,label:string):string { if(!value)throw new Error(`Missing ${label}.`);return value; }
function foreign(map:Map<string,bigint>,value:string|null|undefined,label:string):bigint|null { if(!value)return null;const found=map.get(value);if(!found)throw new Error(`Missing ${label} reference ${value}.`);return found; }
function rows(snapshot:VehicleSnapshot,table:string):Row[] { const data=snapshot.tables[table]?.rows;if(!Array.isArray(data))throw new Error(`Missing export table ${table}.`);return data; }
function sourceDate(row:Row) { return {createdAt:timestamp(row.created_at)??new Date(),updatedAt:timestamp(row.updated_at)??timestamp(row.created_at)??new Date()}; }
export async function importVehicleSnapshot(prisma:PrismaClient,snapshot:VehicleSnapshot,actorId:bigint,applyUserCorrections=false) {
  if(snapshot.database!=='mjrmstlj_mittops')throw new Error('Source must be the reviewed V1 Live database.');
  const requiredTables=['field_vehicles','field_vehicle_events','field_vehicle_components','field_vehicle_maintenance_items','field_vehicle_receipt_drafts','field_vehicle_event_attachments'];
  for(const table of requiredTables)rows(snapshot,table);
  if(!snapshot.tables.field_receipt_expense_drafts&&rows(snapshot,'field_vehicle_receipt_drafts').some(r=>['BUSINESS_EXPENSE','FIELD_OPS_EXPENSE','TOOL_ASSET','EQUIPMENT_ASSET'].includes(r.route_target??'')))throw new Error('The export omitted field_receipt_expense_drafts. Include that table to preserve the existing routed expense history.');
  const hash=createHash('sha256').update(JSON.stringify(snapshot.tables)).digest('hex');
  return prisma.$transaction(async tx=>{
    const actor=await tx.opsUser.findUnique({where:{id:actorId}});if(!actor||!['OWNER','ADMIN'].includes(actor.role)||actor.status!=='ACTIVE')throw new Error('An active owner/admin must run the vehicle import.');
    if(await tx.vehicleMigration.findUnique({where:{id:hash}}))return {alreadyImported:true,hash};
    if(await tx.vehicle.count({where:{legacyId:{not:null}}}))throw new Error('V1 vehicles are already present from another snapshot. Reconcile changes explicitly; do not overwrite them.');
    const vehicles=new Map<string,bigint>(),events=new Map<string,bigint>(),components=new Map<string,bigint>(),receipts=new Map<string,bigint>();
    for(const r of rows(snapshot,'field_vehicles')){
      const v=await tx.vehicle.create({data:{legacyId:Number(r.vehicle_id),name:required(r.vehicle_name,'vehicle name'),modelYear:r.model_year?Number(r.model_year):null,make:r.make,model:r.model,trimName:r.trim_name,vin:r.vin,plate:r.plate,inServiceDate:day(r.in_service_date),outOfServiceDate:day(r.out_of_service_date),currentOdometer:r.current_odometer,acquisitionCost:r.acquisition_cost??'0',expectedResidualValue:r.expected_residual_value??'0',expectedServiceMiles:r.expected_service_miles??'0',fuelMpgEstimate:r.fuel_mpg_estimate??'0',fuelPricePerGallonEstimate:r.fuel_price_per_gallon_estimate??'0',maintenanceReservePerMile:r.maintenance_reserve_per_mile??'0',tireReservePerMile:r.tire_reserve_per_mile??'0',repairReservePerMile:r.repair_reserve_per_mile??'0',depreciationPerMileOverride:r.depreciation_per_mile_override,insuranceAnnualCost:r.insurance_annual_cost??'0',registrationAnnualCost:r.registration_annual_cost??'0',otherFixedAnnualCost:r.other_fixed_annual_cost??'0',expectedAnnualBusinessMiles:r.expected_annual_business_miles??'0',active:r.active==='1',notes:r.notes,legacyRecord:r,...sourceDate(r)}});vehicles.set(required(r.vehicle_id,'vehicle id'),v.id);
    }
    const primary=rows(snapshot,'field_vehicles').filter(r=>r.is_primary==='1'&&r.active==='1');if(primary.length>1)throw new Error('Multiple V1 primary vehicles require review.');
    await tx.vehicleSettings.upsert({where:{id:1},create:{id:1,defaultVehicleId:primary.length?foreign(vehicles,primary[0].vehicle_id,'primary vehicle'):null},update:{defaultVehicleId:primary.length?foreign(vehicles,primary[0].vehicle_id,'primary vehicle'):null}});
    for(const r of rows(snapshot,'field_vehicle_events')){
      const e=await tx.vehicleEvent.create({data:{legacyId:Number(r.vehicle_event_id),vehicleId:foreign(vehicles,r.vehicle_id,'vehicle')!,eventType:required(r.event_type,'event type'),costTreatment:r.cost_treatment??'NORMAL',eventDate:day(r.event_date)!,odometer:r.odometer,vendor:r.vendor,description:required(r.description,'event description'),amount:r.amount,gallons:r.gallons,fuelPricePerGallon:r.fuel_price_per_gallon,amortizeOverMiles:r.amortize_over_miles,notes:r.notes,legacyRecord:r,deletedAt:timestamp(r.deleted_at),...sourceDate(r)}});events.set(required(r.vehicle_event_id,'event id'),e.id);
    }
    for(const r of rows(snapshot,'field_vehicle_components')){
      const c=await tx.vehicleComponent.create({data:{legacyId:Number(r.component_id),vehicleId:foreign(vehicles,r.vehicle_id,'vehicle')!,componentType:required(r.component_type,'component type'),name:required(r.component_name,'component name'),status:r.status??'UNKNOWN',baselineDate:day(r.baseline_date),baselineOdometer:r.baseline_odometer,baselineEventId:foreign(events,r.baseline_vehicle_event_id,'event'),warrantyUntilDate:day(r.warranty_until_date),warrantyUntilMiles:r.warranty_until_miles,warrantyMileageKind:'UNCONFIRMED',notes:r.notes,legacyRecord:r,deletedAt:timestamp(r.deleted_at),...sourceDate(r)}});components.set(required(r.component_id,'component id'),c.id);
    }
    for(const r of rows(snapshot,'field_vehicle_maintenance_items')){
      await tx.vehicleMaintenanceItem.create({data:{legacyId:Number(r.maintenance_item_id),vehicleId:foreign(vehicles,r.vehicle_id,'vehicle')!,componentId:foreign(components,r.component_id,'component'),name:required(r.item_name,'maintenance name'),subsystem:r.subsystem,scheduleSource:r.schedule_source??'MANUAL',clockType:r.clock_type??'VEHICLE',intervalMiles:r.interval_miles,intervalMonths:r.interval_months?Number(r.interval_months):null,baselineOdometer:r.baseline_odometer,baselineDate:day(r.baseline_date),lastEventId:foreign(events,r.last_vehicle_event_id,'event'),estimatedServiceCost:r.estimated_service_cost??'0',nextDueOdometer:r.next_due_odometer,nextDueDate:day(r.next_due_date),active:r.active==='1',notes:r.notes,legacyRecord:r,deletedAt:timestamp(r.deleted_at),...sourceDate(r)}});
    }
    for(const r of rows(snapshot,'field_vehicle_receipt_drafts')){
      const receipt=await tx.vehicleReceipt.create({data:{legacyId:Number(r.receipt_draft_id),vehicleId:foreign(vehicles,r.vehicle_id,'vehicle')!,eventId:foreign(events,r.vehicle_event_id,'event'),category:r.receipt_category??'OTHER',status:r.receipt_status??'CAPTURED',receiptDate:day(r.receipt_date),vendor:r.vendor,amount:r.amount,gallons:r.gallons,fuelPricePerGallon:r.fuel_price_per_gallon,odometer:r.odometer,routeTarget:r.route_target??'UNROUTED',routeStatus:r.route_status??'UNROUTED',routedAt:timestamp(r.routed_at),routeNotes:r.route_notes,parseStatus:r.parse_status??'NOT_PARSED',parseConfidence:r.parse_confidence,rawParseJson:r.raw_parse_json,notes:r.notes,legacyRecord:r,deletedAt:timestamp(r.deleted_at),...sourceDate(r)}});receipts.set(required(r.receipt_draft_id,'receipt id'),receipt.id);
      await tx.vehicleDocument.create({data:{legacyKey:`receipt-${r.receipt_draft_id}`,vehicleId:receipt.vehicleId,receiptId:receipt.id,eventId:receipt.eventId,originalName:r.original_filename??'V1 receipt',mimeType:r.mime_type,byteSize:BigInt(r.file_size_bytes??'0'),sha256:r.checksum_sha256,oneDriveUrl:r.onedrive_web_url,oneDriveItemId:r.onedrive_item_id,legacyRecord:r,createdAt:timestamp(r.created_at)??new Date(),deletedAt:timestamp(r.deleted_at)}});
    }
    for(const r of rows(snapshot,'field_vehicle_event_attachments')){
      const eventId=foreign(events,r.vehicle_event_id,'attachment event')!;const event=await tx.vehicleEvent.findUniqueOrThrow({where:{id:eventId}});
      await tx.vehicleDocument.create({data:{legacyKey:`attachment-${r.attachment_id}`,vehicleId:event.vehicleId,eventId,originalName:r.original_filename??'V1 attachment',mimeType:r.mime_type,byteSize:BigInt(r.file_size_bytes??'0'),oneDriveUrl:r.onedrive_web_url,oneDriveItemId:r.onedrive_item_id,legacyRecord:r,createdAt:timestamp(r.uploaded_at)??new Date(),deletedAt:timestamp(r.deleted_at)}});
    }
    for(const r of snapshot.tables.field_receipt_expense_drafts?.rows??[]){
      await tx.vehicleExpenseDraft.create({data:{legacyId:Number(r.expense_draft_id),receiptId:foreign(receipts,r.receipt_draft_id,'expense receipt')!,status:r.expense_status??'DRAFT',expenseDate:day(r.expense_date),vendor:r.vendor,amount:r.amount??'0',category:r.expense_category??'Business expense',description:r.description??'V1 expense draft',notes:r.notes,legacyAccountingExpenseId:r.accounting_expense_id,receiptOneDriveUrl:r.receipt_onedrive_web_url,receiptOneDriveItemId:r.receipt_onedrive_item_id,legacyRecord:r,deletedAt:timestamp(r.deleted_at),...sourceDate(r)}});
    }
    const missingExpenseHistory=!snapshot.tables.field_receipt_expense_drafts&&rows(snapshot,'field_vehicle_receipt_drafts').some(r=>['BUSINESS_EXPENSE','FIELD_OPS_EXPENSE','TOOL_ASSET','EQUIPMENT_ASSET'].includes(r.route_target??''));
    if(missingExpenseHistory)throw new Error('The export omitted field_receipt_expense_drafts. Include that table to preserve the existing routed expense history.');
    if(applyUserCorrections){
      const engine=await tx.vehicleComponent.findUniqueOrThrow({where:{legacyId:1}});if(engine.componentType!=='ENGINE'||engine.name!=='Replacement engine')throw new Error('Reviewed engine baseline differs.');
      const after=await tx.vehicleComponent.update({where:{id:engine.id},data:{warrantyUntilMiles:null,warrantyMileageKind:'UNLIMITED',warrantyRequirements:'Owner confirmed five-year no-fault warranty with unlimited miles. Maintenance requirements must be checked against the warranty document.'}});
      await tx.opsAuditEvent.create({data:{actorUserId:actorId,action:'vehicle.warranty_corrected',subjectType:'vehicle',subjectId:String(engine.vehicleId),details:serialized({before:engine,after,source:'Owner clarification in migration review'})}});
      const schedule=await tx.vehicleMaintenanceItem.findUniqueOrThrow({where:{legacyId:1}});if(schedule.name!=='Oil and Filter')throw new Error('Reviewed oil schedule differs.');
      const service=await tx.vehicleEvent.create({data:{vehicleId:schedule.vehicleId,eventType:'OIL_CHANGE',eventDate:new Date('2026-09-14T00:00:00Z'),odometer:'84543',amount:null,verificationStatus:'PENDING_CONFIRMATION',description:'Oil and filter service — owner-reported',notes:'Owner reported service on 2026-09-14 at approximately 84,543 miles. Verify the odometer and add the receipt; actual cost is not yet recorded.'}});
      const due=nextDue(service.odometer,service.eventDate,schedule.intervalMiles,schedule.intervalMonths);
      const updated=await tx.vehicleMaintenanceItem.update({where:{id:schedule.id},data:{baselineOdometer:service.odometer,baselineDate:service.eventDate,lastEventId:service.id,...due}});
      await tx.opsAuditEvent.create({data:{actorUserId:actorId,action:'vehicle.maintenance_baseline_updated',subjectType:'vehicle',subjectId:String(schedule.vehicleId),details:serialized({before:schedule,after:updated,service,source:'Owner-reported service, mileage pending confirmation'})}});
    }
    const summary={vehicles:vehicles.size,events:events.size,components:components.size,maintenance:rows(snapshot,'field_vehicle_maintenance_items').length,receipts:receipts.size,expenseDrafts:snapshot.tables.field_receipt_expense_drafts?.rows.length??0,ownerCorrections:applyUserCorrections};
    await tx.vehicleMigration.create({data:{id:hash,sourceDatabase:snapshot.database,sourceExportedAt:snapshot.exportedAt,summary}});
    for(const vehicleId of vehicles.values())await tx.opsAuditEvent.create({data:{actorUserId:actorId,action:'vehicle.v1_imported',subjectType:'vehicle',subjectId:String(vehicleId),details:{hash,summary}}});
    return {alreadyImported:false,hash,summary};
  },{timeout:60000});
}
// CLI preview is read-only. The explicit --apply switch is required for importing.
async function main(){
 const args=process.argv.slice(2),file=args.find(a=>a.endsWith('.json'));
 if(!file)throw new Error('Usage: node dist/vehicle-import.js vehicle-records.json [--apply] [--owner-corrections]');
 const snapshot=JSON.parse(await readFile(file,'utf8')) as VehicleSnapshot;
 console.log(JSON.stringify({sourceDatabase:snapshot.database,tableCounts:Object.fromEntries(Object.entries(snapshot.tables).map(([k,v])=>[k,v.rows.length]))},null,2));
 if(!args.includes('--apply'))return;
 const prisma=new PrismaClient();try{
  const database=new URL(process.env.DATABASE_URL!).pathname.slice(1);if(database!=='mmit_v2')throw new Error('STOP: expected MK2 mmit_v2 database.');
  const owners=await prisma.opsUser.findMany({where:{role:'OWNER',status:'ACTIVE'}});if(owners.length!==1)throw new Error('Exactly one active owner is required for CLI import attribution.');
  console.log(JSON.stringify(await importVehicleSnapshot(prisma,snapshot,owners[0].id,args.includes('--owner-corrections')),null,2));
 }finally{await prisma.$disconnect();}
}
if(process.argv[1]?.endsWith('vehicle-import.js'))main().catch(e=>{console.error(e.message);process.exitCode=1;});
