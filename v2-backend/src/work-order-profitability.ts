import { calculateFieldNationFees } from './fieldnation-fees.js';

// A completed job keeps the vehicle cost model that was assigned to it, so
// later profile edits never rewrite the economics of historical work.
export type DecimalLike = { toString(): string } | string | number | null | undefined;

export type WorkOrderProfitabilityInput = {
  source?: string;
  oaiApplies?: boolean;
  actualGrossPay: DecimalLike;
  mileage: DecimalLike;
  mileageSource: string;
  vehicleCostSnapshot: unknown;
  directCost: DecimalLike;
  trackerStartedAt: Date | null;
  trackerCompletedAt: Date | null;
  driveMinutes: number;
  onsiteMinutes: number;
  adminMinutes: number;
};

type VehicleCostSnapshot = {
  allIn?: unknown;
  complete?: unknown;
  modelVersion?: unknown;
};

function finite(value: DecimalLike): number | null {
  if (value === null || value === undefined || String(value).trim() === '') return null;
  const parsed = Number(value.toString());
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
}

function round(value: number, digits = 2): number {
  return Number(value.toFixed(digits));
}

function snapshot(value: unknown): VehicleCostSnapshot | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as VehicleCostSnapshot
    : null;
}

function trackedMinutes(startedAt: Date | null, completedAt: Date | null): number | null {
  if (!startedAt || !completedAt) return null;
  const milliseconds = completedAt.getTime() - startedAt.getTime();
  return Number.isFinite(milliseconds) && milliseconds > 0 ? round(milliseconds / 60_000, 2) : null;
}

function workOrderMinutes(input: WorkOrderProfitabilityInput): number | null {
  const values = [input.driveMinutes, input.onsiteMinutes, input.adminMinutes];
  return values.every(value => Number.isFinite(value) && value >= 0)
    ? values.reduce((total, value) => total + value, 0)
    : null;
}

/**
 * Uses actual payout and recorded mileage only. A planning estimate is never
 * substituted when the job has not yet been completed and documented.
 */
export function calculateWorkOrderProfitability(input: WorkOrderProfitabilityInput) {
  const payout = finite(input.actualGrossPay);
  const mileage = finite(input.mileage);
  const directCost = finite(input.directCost) ?? 0;
  const vehicle = snapshot(input.vehicleCostSnapshot);
  const vehicleCostPerMile = vehicle ? finite(vehicle.allIn as DecimalLike) : null;
  const vehicleCostComplete = vehicle?.complete === true && vehicleCostPerMile !== null;
  const trackerMinutes = trackedMinutes(input.trackerStartedAt, input.trackerCompletedAt);
  const recordedMinutes = workOrderMinutes(input);
  const totalMinutes = trackerMinutes ?? recordedMinutes;
  const fieldNationFees = input.source === 'FIELD_NATION' && payout !== null
    ? calculateFieldNationFees(payout, input.oaiApplies)
    : null;
  const missing: string[] = [];

  if (payout === null) missing.push('actual payout');
  if (mileage === null) missing.push('recorded mileage');
  if (!vehicle) missing.push('assigned vehicle cost snapshot');
  else if (!vehicleCostComplete) missing.push('complete vehicle cost model');

  const vehicleCost = mileage !== null && vehicleCostPerMile !== null
    ? round(mileage * vehicleCostPerMile)
    : null;
  const complete = missing.length === 0;
  const trueProfit = complete && payout !== null && vehicleCost !== null
    ? round(payout - vehicleCost - directCost - (fieldNationFees?.totalFee ?? 0))
    : null;
  const profitPerHour = trueProfit !== null && totalMinutes !== null && totalMinutes > 0
    ? round(trueProfit / (totalMinutes / 60))
    : null;

  return {
    complete,
    missing,
    actualPayout: payout === null ? null : round(payout),
    payoutBeforeFees: input.source === 'FIELD_NATION',
    fieldNationFeeEstimate: fieldNationFees === null ? null : {
      basis: 'CONFIGURED_RATE_ESTIMATE',
      platformRate: fieldNationFees.platformRate,
      platformFee: fieldNationFees.platformFee,
      insuranceRate: fieldNationFees.insuranceRate,
      insuranceFee: fieldNationFees.insuranceFee,
      oaiRate: fieldNationFees.oaiRate,
      oaiApplies: fieldNationFees.oaiApplies,
      oaiFee: fieldNationFees.oaiFee,
      totalFee: fieldNationFees.totalFee,
    },
    recordedMileage: mileage === null ? null : round(mileage),
    mileageSource: input.mileageSource,
    mileageEvidence: input.mileageSource === 'ODOMETER'
      ? 'ODOMETER'
      : mileage === null ? 'NOT_RECORDED' : 'MANUAL_RECORDED',
    vehicleCostPerMile: vehicleCostPerMile === null ? null : round(vehicleCostPerMile, 4),
    vehicleCost,
    vehicleCostModelComplete: vehicleCostComplete,
    vehicleCostModelVersion: typeof vehicle?.modelVersion === 'number' ? vehicle.modelVersion : null,
    directCost: round(directCost),
    trueProfit,
    trueProfitIncludesFieldNationFeeEstimate: fieldNationFees !== null,
    totalMinutes: totalMinutes === null ? null : round(totalMinutes, 2),
    timeSource: trackerMinutes !== null ? 'TRACKER_OUTING' : recordedMinutes !== null ? 'WORK_ORDER_TOTALS' : 'NOT_RECORDED',
    profitPerDoorToDoorHour: profitPerHour,
  };
}
