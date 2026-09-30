import assert from 'node:assert/strict';
import { parseFieldNationSchedule } from './fieldnation-schedule.js';

const reference = new Date('2026-09-29T12:00:00Z');
const cases: Array<[string, string | null]> = [
  ['QUINCY MI 49082, Oct 2 10:00am', '2026-10-02T14:00:00.000Z'],
  ['CELINA OH 45822, Oct 3 12:00pm', '2026-10-03T16:00:00.000Z'],
  ['Thu, Oct 1, 2026 @ 10:15 PM', '2026-10-02T02:15:00.000Z'],
  ['Jan 15 10:00 AM', '2026-01-15T15:00:00.000Z'],
  ['10/2/2026 10:00 AM', '2026-10-02T14:00:00.000Z'],
  ['2026-10-02T10:00', '2026-10-02T14:00:00.000Z'],
  ['Oct 2 10:00 AM EDT', '2026-10-02T14:00:00.000Z'],
  ['Oct 2 10:00 AM CDT', '2026-10-02T15:00:00.000Z'],
  ['2026-10-02T14:00:00Z', '2026-10-02T14:00:00.000Z'],
  ['2026-10-02T10:00:00-04:00', '2026-10-02T14:00:00.000Z'],
  ['Mar 8 2026 2:30 AM', null],
  ['Nov 1 2026 1:30 AM', null],
  ['Nov 1 2026 1:30 AM EDT', '2026-11-01T05:30:00.000Z'],
  ['Nov 1 2026 1:30 AM EST', '2026-11-01T06:30:00.000Z'],
  ['Feb 30 2026 10:00 AM', null],
  ['Oct 2 13:00 PM', null],
  ['Oct 2 10:99 AM', null],
  ['No schedule supplied', null],
];
for (const [text, expected] of cases) {
  assert.equal(parseFieldNationSchedule(text, reference, 'America/New_York')?.toISOString() ?? null, expected, text);
}
assert.equal(parseFieldNationSchedule('Oct 2 10:00 AM', reference, 'America/Chicago')?.toISOString(), '2026-10-02T15:00:00.000Z');
assert.equal(parseFieldNationSchedule('Oct 2 10:00 AM', reference, 'Invalid/Zone'), null);
console.log(`PASS: ${cases.length + 2} schedule checks; process TZ=${process.env.TZ || 'default'}`);
