const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const page = fs.readFileSync(path.join(__dirname, '../admin/field_rideshare.php'), 'utf8');
const script = page.match(/<script>([\s\S]*?)<\/script>/)[1];
new vm.Script(script); // Verify the complete form script, not a rewritten test implementation.
const flex = script.match(/const updateFlex = \(\) => \{[\s\S]*?\n  \};/)[0];
const online = script.match(/const updateOnlineMinutes = \(\) => \{[\s\S]*?\n  \};/)[0];
const elements = Object.fromEntries(['started_at', 'ended_at', 'online_minutes', 'flex_scheduled_start_at', 'start-label', 'end-label', 'work-minutes-label', 'flex-timing'].map(id => [id, {value: '', textContent: ''}]));
Object.assign(elements.started_at, {value: '2026-10-13T13:20:32'});
Object.assign(elements.ended_at, {value: '2026-10-13T17:09:20'});
Object.assign(elements.flex_scheduled_start_at, {value: '2026-10-13T13:30:00'});
const context = {
  document: {getElementById: id => elements[id], querySelectorAll: () => []},
  platform: {value: 'AMAZON_FLEX'}, flexFields: {querySelectorAll: () => []},
  blockHours: {value: '4'}, scheduledEnd: {value: ''}, breakSummary: null,
  totalBreakMinutes: () => 0, numberValue: () => 90, money: n => n.toFixed(2),
  localDateTime: date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}T${String(date.getHours()).padStart(2,'0')}:${String(date.getMinutes()).padStart(2,'0')}`,
};
vm.createContext(context);
vm.runInContext(`${flex}\n${online}\nupdateOnlineMinutes();`, context);
assert.equal(elements.online_minutes.value, '229');
assert.equal(context.scheduledEnd.value, '2026-10-13T17:30');
assert.match(elements['flex-timing'].textContent, /21 minutes early/);
assert.equal(context.blockHours.required, true);
context.totalBreakMinutes = () => 12;
vm.runInContext('updateOnlineMinutes()', context);
assert.equal(elements.online_minutes.value, '217');
// Manually entered blocks without export schedule retain their previous fallback.
elements.flex_scheduled_start_at.value = '';
vm.runInContext('updateFlex()', context);
assert.equal(context.scheduledEnd.value, '2026-10-13T17:20');
context.platform.value = 'LYFT';
vm.runInContext('updateOnlineMinutes()', context);
assert.equal(context.flexFields.hidden, true);
assert.equal(context.blockHours.required, false);
assert.equal(elements.online_minutes.value, '217');
console.log('PASS: form JS syntax, imported schedule/departure separation, breaks, manual fallback, and Lyft behavior');
