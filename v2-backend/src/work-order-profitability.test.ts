import assert from 'node:assert/strict';
import { calculateWorkOrderProfitability } from './work-order-profitability.js';

let checks = 0;
const equal = (actual: unknown, expected: unknown) => { assert.equal(actual, expected); checks += 1; };
const base = {
  actualGrossPay: '200.00', mileage: '42.00', mileageSource: 'ODOMETER',
  vehicleCostSnapshot: { allIn: 0.236, complete: true, modelVersion: 1 }, directCost: '10.00',
  trackerStartedAt: new Date('2026-10-02T22:00:00.000Z'), trackerCompletedAt: new Date('2026-10-03T03:00:00.000Z'),
  driveMinutes: 50, onsiteMinutes: 220, adminMinutes: 30,
};

const complete = calculateWorkOrderProfitability(base);
equal(complete.complete, true);
equal(complete.vehicleCost, 9.91);
equal(complete.trueProfit, 180.09);
equal(complete.totalMinutes, 300);
equal(complete.timeSource, 'TRACKER_OUTING');
equal(complete.profitPerDoorToDoorHour, 36.02);
equal(complete.mileageEvidence, 'ODOMETER');

const manual = calculateWorkOrderProfitability({ ...base, mileageSource: 'MANUAL_UNVERIFIED' });
equal(manual.complete, true);
equal(manual.mileageEvidence, 'MANUAL_RECORDED');

const incompleteVehicle = calculateWorkOrderProfitability({ ...base, vehicleCostSnapshot: { allIn: 0.24, complete: false } });
equal(incompleteVehicle.complete, false);
equal(incompleteVehicle.trueProfit, null);
equal(incompleteVehicle.missing.join(', '), 'complete vehicle cost model');

const missingMileage = calculateWorkOrderProfitability({ ...base, mileage: null });
equal(missingMileage.complete, false);
equal(missingMileage.vehicleCost, null);
equal(missingMileage.missing.join(', '), 'recorded mileage');

const fallbackTime = calculateWorkOrderProfitability({ ...base, trackerCompletedAt: null });
equal(fallbackTime.totalMinutes, 300);
equal(fallbackTime.timeSource, 'WORK_ORDER_TOTALS');

console.log(`PASS: ${checks} actual work-order profitability checks`);
