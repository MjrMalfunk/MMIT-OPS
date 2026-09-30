// Estimates never establish that a trip actually occurred.
export function estimatedRoundTrip(mileage: string | null, parsedData: unknown): string | null {
  if (mileage === null || !parsedData || typeof parsedData !== 'object' || Array.isArray(parsedData)) return null;
  const data = parsedData as Record<string, unknown>;
  const opportunity = data.opportunity;
  if (!opportunity || typeof opportunity !== 'object' || Array.isArray(opportunity)) return null;
  const kind = (opportunity as Record<string, unknown>).distanceKind;
  if (kind !== 'ONE_WAY' && kind !== 'ROUND_TRIP') return null;
  if (!/^\d+(?:\.\d{1,2})?$/.test(mileage)) return null;
  const cents = Math.round(Number(mileage) * 100) * (kind === 'ONE_WAY' ? 2 : 1);
  if (!Number.isSafeInteger(cents) || cents > 9999999999) return null;
  return (cents / 100).toFixed(2);
}
