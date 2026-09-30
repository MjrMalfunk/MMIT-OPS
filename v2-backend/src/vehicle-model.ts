// Vehicle planning costs and maintenance clocks remain independent of tax mileage.
export type DecimalLike = { toString(): string } | string | number | null;
export type VehicleProfile = {
  fuelMpgEstimate: DecimalLike; fuelPricePerGallonEstimate: DecimalLike;
  maintenanceReservePerMile: DecimalLike; tireReservePerMile: DecimalLike;
  repairReservePerMile: DecimalLike; depreciationPerMileOverride: DecimalLike;
  acquisitionCost: DecimalLike; expectedResidualValue: DecimalLike;
  expectedServiceMiles: DecimalLike; insuranceAnnualCost: DecimalLike;
  registrationAnnualCost: DecimalLike; otherFixedAnnualCost: DecimalLike;
  expectedAnnualBusinessMiles: DecimalLike;
};
export type FuelEvent = { eventType: string; eventDate: Date; amount: DecimalLike; gallons: DecimalLike; deletedAt: Date | null };
export function number(value: DecimalLike): number { return value === null ? 0 : Number(value.toString()); }
export function round(value: number, digits = 4): number { return Number(value.toFixed(digits)); }
export function businessToday(now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
  return ['year', 'month', 'day'].map(key => parts.find(p => p.type === key)!.value).join('-');
}
export function dateOnly(value: Date | null): string | null { return value?.toISOString().slice(0, 10) ?? null; }
export function vehicleCost(profile: VehicleProfile, events: FuelEvent[], today = businessToday()) {
  const lower = new Date(today + 'T00:00:00Z'); lower.setUTCDate(lower.getUTCDate() - 90);
  const fuelEvents = events.filter(e => !e.deletedAt && e.eventType === 'FUEL' && number(e.gallons) > 0 && number(e.amount) > 0 && e.eventDate >= lower && dateOnly(e.eventDate)! <= today);
  const spend = fuelEvents.reduce((sum, e) => sum + number(e.amount), 0);
  const gallons = fuelEvents.reduce((sum, e) => sum + number(e.gallons), 0);
  const price = gallons > 0 ? round(spend / gallons) : number(profile.fuelPricePerGallonEstimate);
  const mpg = number(profile.fuelMpgEstimate);
  const fuel = mpg > 0 ? price / mpg : 0;
  const maintenance = number(profile.maintenanceReservePerMile), tires = number(profile.tireReservePerMile), repairs = number(profile.repairReservePerMile);
  const serviceMiles = number(profile.expectedServiceMiles);
  const depreciation = profile.depreciationPerMileOverride !== null ? number(profile.depreciationPerMileOverride) : serviceMiles > 0 ? Math.max(0, number(profile.acquisitionCost) - number(profile.expectedResidualValue)) / serviceMiles : 0;
  const annualFixed = number(profile.insuranceAnnualCost) + number(profile.registrationAnnualCost) + number(profile.otherFixedAnnualCost);
  const annualMiles = number(profile.expectedAnnualBusinessMiles);
  const fixed = annualMiles > 0 ? annualFixed / annualMiles : 0;
  const warnings: string[] = [];
  if (!(mpg > 0 && price > 0)) warnings.push('Fuel cost needs a positive MPG and fuel price.');
  if (annualFixed > 0 && annualMiles <= 0) warnings.push('Annual business mileage is needed to allocate fixed costs.');
  if (profile.depreciationPerMileOverride === null && number(profile.acquisitionCost) > number(profile.expectedResidualValue) && serviceMiles <= 0) warnings.push('Expected service miles are needed to allocate depreciation.');
  return { fuelPrice: round(price), fuelPriceSource: gallons > 0 ? 'OBSERVED_90_DAY' : 'PROFILE', mpg, fuel: round(fuel), maintenance: round(maintenance), tires: round(tires), repairs: round(repairs), depreciation: round(depreciation), depreciationSource: profile.depreciationPerMileOverride !== null ? 'OVERRIDE' : 'PROFILE_BASIS', fixed: round(fixed), annualFixed: round(annualFixed, 2), annualBusinessMiles: annualMiles, allIn: round(fuel + maintenance + tires + repairs + depreciation + fixed), warnings, complete: warnings.length === 0, modelVersion: 1 };
}
export type MaintenanceClock = {
  baselineOdometer: DecimalLike; baselineDate: Date | null; intervalMiles: DecimalLike;
  intervalMonths: number | null; active: boolean; deletedAt: Date | null;
  nextDueOdometer: DecimalLike; nextDueDate: Date | null;
};
// Clamp month-end dates so Jan 31 + one month remains in February.
export function nextDue(baselineOdometer: DecimalLike, baselineDate: Date | null, intervalMiles: DecimalLike, intervalMonths: number | null) {
  const mileage = baselineOdometer !== null && number(intervalMiles) > 0 ? round(number(baselineOdometer) + number(intervalMiles), 2) : null;
  let date: Date | null = null;
  if (baselineDate && intervalMonths !== null && intervalMonths > 0) {
    const day = baselineDate.getUTCDate(); date = new Date(baselineDate);
    date.setUTCDate(1); date.setUTCMonth(date.getUTCMonth() + intervalMonths);
    const last = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
    date.setUTCDate(Math.min(day, last));
  }
  return { nextDueOdometer: mileage, nextDueDate: date };
}
export function maintenanceState(item: MaintenanceClock, odometer: DecimalLike, today = businessToday()) {
  if (!item.active || item.deletedAt) return 'DISABLED';
  const hasMiles = number(item.intervalMiles) > 0, hasMonths = (item.intervalMonths ?? 0) > 0;
  let state = 'CURRENT', soon = false, incomplete = !hasMiles && !hasMonths;
  if (hasMiles) {
    if (item.nextDueOdometer === null || odometer === null) incomplete = true;
    else { const remaining = number(item.nextDueOdometer) - number(odometer); if (remaining < 0) state = 'OVERDUE'; else if (remaining === 0) state = 'DUE'; else if (remaining <= 500) soon = true; }
  }
  if (hasMonths) {
    if (!item.nextDueDate) incomplete = true;
    else { const days = Math.round((item.nextDueDate.getTime() - new Date(today + 'T00:00:00Z').getTime()) / 86400000); if (days < 0) state = 'OVERDUE'; else if (days === 0 && state !== 'OVERDUE') state = 'DUE'; else if (days <= 30) soon = true; }
  }
  if (state !== 'CURRENT') return state;
  if (incomplete) return 'BASELINE_NEEDED';
  return soon ? 'DUE_SOON' : 'CURRENT';
}
export function warrantyState(component: { warrantyUntilDate: Date | null; warrantyUntilMiles: DecimalLike; warrantyMileageKind: string; baselineOdometer: DecimalLike }, odometer: DecimalLike, today = businessToday()) {
  const date = dateOnly(component.warrantyUntilDate);
  const rawLimit = component.warrantyMileageKind === 'UNLIMITED' ? null : component.warrantyUntilMiles;
  const limit = rawLimit === null ? null : component.warrantyMileageKind === 'ABSOLUTE_ODOMETER' ? number(rawLimit) : component.warrantyMileageKind === 'SINCE_BASELINE' && component.baselineOdometer !== null ? number(rawLimit) + number(component.baselineOdometer) : null;
  const mileageUnconfirmed = rawLimit !== null && limit === null;
  const datePassed = date !== null && date < today, milesPassed = limit !== null && odometer !== null && number(odometer) > limit;
  return { recordedUntilDate: date, recordedMileage: rawLimit?.toString() ?? null, mileageKind: component.warrantyMileageKind, endOdometer: limit, mileageUnconfirmed, status: datePassed || milesPassed ? 'RECORDED_LIMIT_PASSED' : mileageUnconfirmed ? 'MILEAGE_UNCONFIRMED' : date || limit !== null ? 'RECORDED_LIMIT_CURRENT' : 'NOT_RECORDED' };
}
