const MAX_PACKET_BYTES = 4 * 1024 * 1024;
const UUID_PATTERN = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const GPS_PHASES = ['EN_ROUTE_SITE', 'ON_SITE', 'WORKING', 'WRAP_UP', 'RETURNING_HOME'] as const;
const EVENT_SEQUENCE = [
  'SHIFT_STARTED',
  'FN_TRIP_STARTED',
  'FN_ARRIVED_SITE',
  'FN_CHECKED_IN',
  'FN_WORK_COMPLETED',
  'FN_CHECKED_OUT',
] as const;

type JsonObject = Record<string, unknown>;
type GpsPhase = (typeof GPS_PHASES)[number];
type EventType = (typeof EVENT_SEQUENCE)[number] | 'FN_RETURN_STARTED' | 'FN_RETURN_COMPLETED' | 'OUTING_COMPLETED';

export class TrackerPacketValidationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'TrackerPacketValidationError';
  }
}

export type TrackerSegment = {
  label: string;
  entryType: 'drive' | 'admin' | 'onsite';
  startedAt: string;
  endedAt: string;
  durationMs: number;
  minutes: number;
};

export type TrackerMetrics = {
  shiftId: string;
  workOrderNumber: string;
  roundTripExpected: boolean;
  startOdometer: number;
  endOdometer: number;
  mileage: number;
  eventIds: string[];
  times: Record<string, number>;
  segments: TrackerSegment[];
  totals: { drive: number; onsite: number; admin: number };
  warnings: string[];
  gpsMiles: number | null;
  gpsPoints: number;
  gpsValidPoints: number;
  gpsLinks: number;
  gpsSkipped: number;
  workMinutesExact: number;
  wrapMinutesExact: number;
  postCheckoutMinutes: number;
  changes: {
    checkedInAt: string;
    checkedOutAt: string;
    actualLeftSiteAt: string;
    mileage: number;
    driveMinutes: number;
    onsiteMinutes: number;
  };
};

export type ParsedTrackerPacket = {
  packetSchema: 'mmit.work-tracker.v2';
  shiftId: string;
  workOrderNumber: string;
  startedAt: Date;
  completedAt: Date;
  checkInAt: Date;
  checkOutAt: Date;
  mileage: number;
  rawPacket: JsonObject;
  metrics: TrackerMetrics;
};

function invalid(message: string): never {
  throw new TrackerPacketValidationError(message);
}

function objectValue(value: unknown, label: string): JsonObject {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) invalid(`${label} must be an object.`);
  return value as JsonObject;
}

function uuid(value: unknown, label: string): string {
  if (typeof value !== 'string' || !UUID_PATTERN.test(value)) invalid(`Invalid ${label} UUID.`);
  return value.toLowerCase();
}

function epoch(value: unknown, label: string): number {
  if (typeof value !== 'number' || !Number.isInteger(value) || value < 1_577_836_800_000 || value > 4_102_444_800_000) {
    invalid(`Invalid ${label} epoch-millisecond timestamp.`);
  }
  return value;
}

function boundedNumber(value: unknown, label: string, min: number, max: number): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < min || value > max) invalid(`Invalid ${label}.`);
  return value;
}

function closingNote(value: unknown): string | null {
  if (value === undefined || value === null) return null;
  if (typeof value !== 'string') invalid('Closing note must be text.');
  const trimmed = value.trim();
  if (trimmed.length > 10_000 || trimmed.includes('\0')) invalid('Closing note is too long or contains invalid text.');
  return trimmed || null;
}

/** Stable JSON is used for retry identity so property-order changes do not defeat idempotency. */
export function canonicalJson(value: unknown): string {
  if (value === null || typeof value === 'string' || typeof value === 'boolean' || typeof value === 'number') {
    if (typeof value === 'number' && !Number.isFinite(value)) invalid('Tracker packet contains a non-finite number.');
    return JSON.stringify(value) as string;
  }
  if (Array.isArray(value)) return `[${value.map((item) => canonicalJson(item)).join(',')}]`;
  if (typeof value === 'object') {
    const record = value as Record<string, unknown>;
    return `{${Object.keys(record).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(record[key])}`).join(',')}}`;
  }
  invalid('Tracker packet contains an unsupported value.');
}

function iso(epochMs: number): string {
  return new Date(epochMs).toISOString();
}

function segment(
  label: string,
  entryType: TrackerSegment['entryType'],
  startedAt: number,
  endedAt: number,
): TrackerSegment {
  return {
    label,
    entryType,
    startedAt: iso(startedAt),
    endedAt: iso(endedAt),
    durationMs: endedAt - startedAt,
    minutes: Math.ceil((endedAt - startedAt) / 60_000),
  };
}

function milesBetween(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const radiusMiles = 3958.7613;
  const latA = a.latitude * Math.PI / 180;
  const latB = b.latitude * Math.PI / 180;
  const deltaLat = (b.latitude - a.latitude) * Math.PI / 180;
  const deltaLon = (b.longitude - a.longitude) * Math.PI / 180;
  const haversine = Math.sin(deltaLat / 2) ** 2
    + Math.cos(latA) * Math.cos(latB) * Math.sin(deltaLon / 2) ** 2;
  return radiusMiles * 2 * Math.asin(Math.sqrt(Math.min(1, Math.max(0, haversine))));
}

export function parseTrackerPacket(input: unknown): ParsedTrackerPacket {
  const root = objectValue(input, 'Tracker packet');
  const serialized = canonicalJson(root);
  if (Buffer.byteLength(serialized, 'utf8') > MAX_PACKET_BYTES) invalid('Choose one JSON export no larger than 4 MiB.');
  if (root.schema !== 'mmit.work-tracker.v2') invalid('Expected an MMIT Work Tracker v0.2 export.');

  const shift = objectValue(root.shift, 'shift');
  if (shift.platform !== 'FIELD_NATION') invalid('This import accepts Field Nation outings only.');
  if (typeof shift.roundTripExpected !== 'boolean' || !Array.isArray(root.rides) || root.rides.length !== 0) {
    invalid('Invalid Field Nation round-trip flag or unexpected passenger rides.');
  }

  const shiftId = uuid(shift.id, 'outing');
  const workOrderNumberValue = shift.workOrderNumber;
  if (typeof workOrderNumberValue !== 'string') invalid('Invalid work-order number.');
  const workOrderNumber = workOrderNumberValue.trim();
  if (!workOrderNumber || workOrderNumber.length > 120 || /[\x00-\x1f\x7f]/.test(workOrderNumber)) invalid('Invalid work-order number.');

  const roundTripExpected = shift.roundTripExpected;
  closingNote(shift.closingNote);
  const startedAtMs = epoch(shift.startedAtEpochMs, 'startedAt');
  const completedAtMs = epoch(shift.completedAtEpochMs, 'completedAt');
  const exportedAtMs = epoch(root.exportedAtEpochMs, 'exportedAt');
  const wentOfflineAtMs = epoch(shift.wentOfflineAtEpochMs, 'wentOfflineAt');
  const homeArrivedAtMs = epoch(shift.homeArrivedAtEpochMs, 'homeArrivedAt');
  if (completedAtMs < startedAtMs || completedAtMs - startedAtMs > 172_800_000 || exportedAtMs < completedAtMs) {
    invalid('Outing must be completed, chronologically valid, and no longer than 48 hours.');
  }

  const startOdometer = boundedNumber(shift.startOdometer, 'starting odometer', 0, 9_999_999);
  const endOdometer = boundedNumber(shift.endOdometer, 'ending odometer', startOdometer, 9_999_999);
  if (endOdometer - startOdometer > 3_000) invalid('Outing exceeds the 3,000-mile pilot limit.');

  const sequence: readonly EventType[] = roundTripExpected
    ? [...EVENT_SEQUENCE, 'FN_RETURN_STARTED', 'FN_RETURN_COMPLETED']
    : [...EVENT_SEQUENCE, 'OUTING_COMPLETED'];
  const events = root.events;
  if (!Array.isArray(events) || events.length !== sequence.length) {
    invalid('Incomplete or unsupported Field Nation event sequence. Export a completed outing.');
  }

  const eventIds: string[] = [];
  const seenEventIds = new Set<string>();
  const times: Record<string, number> = {};
  let previousEventMs = startedAtMs;
  events.forEach((rawEvent, index) => {
    const event = objectValue(rawEvent, `events[${index}]`);
    const expectedType = sequence[index]!;
    if (event.type !== expectedType || event.rideId) invalid(`Expected ${expectedType} at event ${index + 1}.`);
    const eventId = uuid(event.id, 'event');
    if (seenEventIds.has(eventId)) invalid('Duplicate event UUID in this export.');
    seenEventIds.add(eventId);
    eventIds.push(eventId);
    const occurredAtMs = epoch(event.occurredAtEpochMs, `${expectedType}.occurredAt`);
    if (occurredAtMs < previousEventMs || occurredAtMs < startedAtMs || occurredAtMs > completedAtMs) {
      invalid('Events are out of order or outside the outing. Do not sort or edit the export.');
    }
    times[expectedType] = occurredAtMs;
    previousEventMs = occurredAtMs;
  });

  const startPayload = objectValue(objectValue(events[0], 'SHIFT_STARTED').payload, 'SHIFT_STARTED.payload');
  if (startPayload.workOrderNumber !== workOrderNumber
    || startPayload.roundTripExpected !== roundTripExpected
    || times.SHIFT_STARTED !== startedAtMs
    || times.FN_TRIP_STARTED !== startedAtMs
    || previousEventMs !== completedAtMs
    || wentOfflineAtMs !== times.FN_CHECKED_OUT
    || homeArrivedAtMs !== completedAtMs
    || (roundTripExpected && times.FN_RETURN_STARTED !== times.FN_CHECKED_OUT)) {
    invalid('Shift metadata does not agree with the event timeline.');
  }

  const warnings: string[] = [];
  if (completedAtMs - startedAtMs < 300_000) warnings.push('Outing is under five minutes; this may be a stationary test.');
  if (endOdometer === startOdometer) warnings.push('Odometer mileage is zero. Confirm this was intentional.');
  if (completedAtMs > Date.now() + 300_000) warnings.push('Outing is in the future. Check the phone clock.');
  if (!roundTripExpected) warnings.push('One-way outing: post-checkout time is excluded. Confirm the ending odometer belongs to this job, not travel to the next job.');

  const segments: TrackerSegment[] = [
    segment('Outbound drive', 'drive', startedAtMs, times.FN_ARRIVED_SITE),
    segment('Arrival / waiting before check-in', 'admin', times.FN_ARRIVED_SITE, times.FN_CHECKED_IN),
    segment('Onsite (check-in through checkout)', 'onsite', times.FN_CHECKED_IN, times.FN_CHECKED_OUT),
  ];
  if (roundTripExpected) segments.push(segment('Return drive', 'drive', times.FN_RETURN_STARTED, completedAtMs));
  const totals = segments.reduce((sum, item) => {
    sum[item.entryType] += item.minutes;
    return sum;
  }, { drive: 0, onsite: 0, admin: 0 });

  const locations = root.locations;
  if (!Array.isArray(locations) || locations.length > 25_000) invalid('Invalid GPS array (maximum 25,000 points).');
  let gpsMiles = 0;
  let gpsLinks = 0;
  let gpsSkipped = 0;
  let gpsValidPoints = 0;
  let lastDrivingPoint: { time: number; latitude: number; longitude: number; phase: GpsPhase } | null = null;
  let lastLocationMs = startedAtMs;

  locations.forEach((rawPoint, index) => {
    const point = objectValue(rawPoint, `locations[${index}]`);
    const occurredAtMs = epoch(point.occurredAtEpochMs, 'GPS');
    const latitude = boundedNumber(point.latitude, 'GPS latitude', -90, 90);
    const longitude = boundedNumber(point.longitude, 'GPS longitude', -180, 180);
    const accuracyMeters = boundedNumber(point.accuracyMeters, 'GPS accuracy', 0, 100_000);
    const phase = point.phase;
    if (typeof phase !== 'string' || !(GPS_PHASES as readonly string[]).includes(phase) || occurredAtMs < lastLocationMs || occurredAtMs > completedAtMs) {
      invalid('GPS times or phases are invalid.');
    }
    lastLocationMs = occurredAtMs;
    const driving = (phase === 'EN_ROUTE_SITE' && occurredAtMs <= times.FN_ARRIVED_SITE)
      || (roundTripExpected && phase === 'RETURNING_HOME' && occurredAtMs >= times.FN_CHECKED_OUT);
    if (!driving || accuracyMeters > 50) {
      lastDrivingPoint = null;
      gpsSkipped += 1;
      return;
    }
    gpsValidPoints += 1;
    if (lastDrivingPoint !== null && lastDrivingPoint.phase === phase) {
      const seconds = (occurredAtMs - lastDrivingPoint.time) / 1_000;
      const miles = milesBetween({ latitude, longitude }, lastDrivingPoint);
      if (seconds > 0 && seconds <= 60 && miles / seconds * 3_600 <= 100) {
        gpsMiles += miles;
        gpsLinks += 1;
      } else {
        gpsSkipped += 1;
      }
    }
    lastDrivingPoint = { time: occurredAtMs, latitude, longitude, phase: phase as GpsPhase };
  });

  const mileage = Number((endOdometer - startOdometer).toFixed(2));
  if (gpsLinks === 0) warnings.push('Not enough usable driving GPS points to compare mileage.');
  if (gpsSkipped > 0) warnings.push('GPS comparison excludes stationary phases, poor fixes, gaps, or jumps; it is not a complete route measurement.');
  if (gpsLinks > 0 && Math.abs(gpsMiles - mileage) > Math.max(2, mileage * 0.2)) warnings.push('GPS segments and odometer miles differ by more than 20% (or two miles). Review the readings and route.');

  const metrics: TrackerMetrics = {
    shiftId,
    workOrderNumber,
    roundTripExpected,
    startOdometer,
    endOdometer,
    mileage,
    eventIds,
    times,
    segments,
    totals,
    warnings,
    gpsMiles: gpsLinks > 0 ? Number(gpsMiles.toFixed(2)) : null,
    gpsPoints: locations.length,
    gpsValidPoints,
    gpsLinks,
    gpsSkipped,
    workMinutesExact: (times.FN_WORK_COMPLETED - times.FN_CHECKED_IN) / 60_000,
    wrapMinutesExact: (times.FN_CHECKED_OUT - times.FN_WORK_COMPLETED) / 60_000,
    postCheckoutMinutes: roundTripExpected ? 0 : (completedAtMs - times.FN_CHECKED_OUT) / 60_000,
    changes: {
      checkedInAt: iso(times.FN_CHECKED_IN),
      checkedOutAt: iso(times.FN_CHECKED_OUT),
      actualLeftSiteAt: iso(times.FN_CHECKED_OUT),
      mileage,
      driveMinutes: totals.drive,
      onsiteMinutes: totals.onsite,
    },
  };

  return {
    packetSchema: 'mmit.work-tracker.v2',
    shiftId,
    workOrderNumber,
    startedAt: new Date(startedAtMs),
    completedAt: new Date(completedAtMs),
    checkInAt: new Date(times.FN_CHECKED_IN),
    checkOutAt: new Date(times.FN_CHECKED_OUT),
    mileage,
    rawPacket: root,
    metrics,
  };
}
