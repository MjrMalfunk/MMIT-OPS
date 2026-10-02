export type FieldNationFeeSchedule = {
  platformRate: number;
  insuranceRate: number;
  oaiRate: number;
  oaiApplies: boolean;
};

function configuredRate(value: string | undefined, fallback: number): number {
  if (value === undefined || value.trim() === '') return fallback;
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed >= 0 && parsed <= 1 ? parsed : fallback;
}

export function fieldNationFeeSchedule(oaiApplies?: boolean): FieldNationFeeSchedule {
  return {
    platformRate: 0.10,
    insuranceRate: configuredRate(process.env.FIELDNATION_INSURANCE_FEE_RATE, 0.0195),
    oaiRate: configuredRate(process.env.FIELDNATION_OAI_FEE_RATE, 0.005),
    oaiApplies: oaiApplies ?? String(process.env.FIELDNATION_PROFIT_OAI_APPLIES ?? 'false').toLowerCase() === 'true',
  };
}

function money(value: number): number {
  return Math.round(value * 100) / 100;
}

export function calculateFieldNationFees(gross: number, oaiApplies?: boolean) {
  const schedule = fieldNationFeeSchedule(oaiApplies);
  const platformFee = money(gross * schedule.platformRate);
  const insuranceFee = money(gross * schedule.insuranceRate);
  const oaiFee = schedule.oaiApplies ? money(gross * schedule.oaiRate) : 0;
  return {
    ...schedule,
    platformFee,
    insuranceFee,
    oaiFee,
    totalFee: money(platformFee + insuranceFee + oaiFee),
  };
}
