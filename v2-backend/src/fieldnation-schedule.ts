/** Resolve FieldNation wall-clock schedules independently of the server TZ. */
export function parseFieldNationSchedule(
  text: string,
  referenceDate: Date = new Date(),
  timeZone: string = process.env.FIELDNATION_SCHEDULE_TIME_ZONE || 'America/New_York',
): Date | null {
  const value = text.trim();
  // An explicit ISO offset is already an instant; never apply a second offset.
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2})$/i.test(value)) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  }
  const named = value.match(/\b([A-Za-z]{3,9})\s+(\d{1,2}),?\s*(?:(\d{4})\s*)?(?:@|at)?\s*(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\b/i);
  const numeric = value.match(/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\s*(?:@|at)?\s*(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\b/i);
  const isoLocal = value.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/);
  let year: number, month: number, day: number, hour: number, minute: number, second = 0;
  if (named || numeric) {
    const match = (named || numeric)!;
    year = Number(match[3] || referenceDate.getUTCFullYear());
    month = named ? ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].indexOf(match[1].slice(0,3).toLowerCase()) + 1 : Number(match[1]);
    day = Number(match[2]);
    const hour12 = Number(match[4]);
    if (hour12 < 1 || hour12 > 12) return null;
    hour = hour12 % 12 + (match[6].toUpperCase() === 'PM' ? 12 : 0);
    minute = Number(match[5] || 0);
  } else if (isoLocal) {
    [year, month, day, hour, minute, second] = isoLocal.slice(1).map(part => Number(part || 0));
  } else return null;
  if (year < 1900 || year > 9999 || month < 1 || month > 12 || day < 1 || hour > 23 || minute > 59 || second > 59) return null;
  const wall = Date.UTC(year, month - 1, day, hour, minute, second);
  const calendar = new Date(wall);
  if (calendar.getUTCMonth() !== month - 1 || calendar.getUTCDate() !== day) return null;
  const scheduleMatch = named || numeric;
  const suffix = scheduleMatch ? value.slice((scheduleMatch.index ?? 0) + scheduleMatch[0].length) : '';
  const explicitZone = suffix.match(/^\s*\(?\s*(EST|EDT|CST|CDT|MST|MDT|PST|PDT|UTC|GMT)\b/i)?.[1].toUpperCase();
  if (explicitZone) {
    const offsets: Record<string, number> = {EST:-5,EDT:-4,CST:-6,CDT:-5,MST:-7,MDT:-6,PST:-8,PDT:-7,UTC:0,GMT:0};
    return new Date(wall - offsets[explicitZone] * 3600000);
  }
  // Collect offsets on both sides of DST transitions, then round-trip candidates.
  // Missing spring hours and duplicated autumn hours need explicit review.
  try {
    const formatter = new Intl.DateTimeFormat('en-US', {timeZone, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23'});
    const project = (instant: number): number => {
      const parts: Record<string,string> = {};
      for (const part of formatter.formatToParts(new Date(instant))) parts[part.type] = part.value;
      return Date.UTC(Number(parts.year), Number(parts.month)-1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second));
    };
    const offsets = new Set([-36, 0, 36].map(hours => {
      const instant = wall + hours * 3600000;
      return project(instant) - instant;
    }));
    const candidates = [...offsets].map(offset => wall - offset).filter(instant => project(instant) === wall);
    return candidates.length === 1 ? new Date(candidates[0]) : null;
  } catch { return null; }
}
