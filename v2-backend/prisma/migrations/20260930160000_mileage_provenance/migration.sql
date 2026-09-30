-- Preserve unknown historical values; identify only matching odometer evidence.
ALTER TABLE `WorkOrder`
  ADD COLUMN `estimatedMileage` DECIMAL(10,2) NULL,
  ADD COLUMN `mileageSource` VARCHAR(32) NOT NULL DEFAULT 'UNVERIFIED';

UPDATE `WorkOrder` w JOIN `WorkOrderTrackerImport` t ON t.workOrderId = w.id
SET w.mileageSource = 'ODOMETER'
WHERE JSON_TYPE(JSON_EXTRACT(t.metrics, '$.startOdometer')) IN ('INTEGER', 'DOUBLE')
  AND JSON_TYPE(JSON_EXTRACT(t.metrics, '$.endOdometer')) IN ('INTEGER', 'DOUBLE')
  AND JSON_TYPE(JSON_EXTRACT(t.metrics, '$.mileage')) IN ('INTEGER', 'DOUBLE')
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(t.metrics, '$.endOdometer')) AS DECIMAL(12,2)) >= CAST(JSON_UNQUOTE(JSON_EXTRACT(t.metrics, '$.startOdometer')) AS DECIMAL(12,2))
  AND w.mileage = CAST(JSON_UNQUOTE(JSON_EXTRACT(t.metrics, '$.mileage')) AS DECIMAL(10,2))
  AND w.mileage = CAST(JSON_UNQUOTE(JSON_EXTRACT(t.metrics, '$.endOdometer')) AS DECIMAL(12,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(t.metrics, '$.startOdometer')) AS DECIMAL(12,2));

-- Move explicitly identified estimates only when no tracker record exists.
UPDATE `WorkOrder` w JOIN `FieldNationImport` f ON f.workOrderId = w.id
LEFT JOIN `WorkOrderTrackerImport` t ON t.workOrderId = w.id
SET w.estimatedMileage = f.mileage * IF(JSON_UNQUOTE(JSON_EXTRACT(f.parsedData, '$.opportunity.distanceKind')) = 'ONE_WAY', 2, 1),
    w.mileage = NULL
WHERE t.id IS NULL AND w.mileage = f.mileage AND f.mileage >= 0
  AND JSON_UNQUOTE(JSON_EXTRACT(f.parsedData, '$.opportunity.distanceKind')) IN ('ONE_WAY', 'ROUND_TRIP')
  AND f.mileage * IF(JSON_UNQUOTE(JSON_EXTRACT(f.parsedData, '$.opportunity.distanceKind')) = 'ONE_WAY', 2, 1) <= 99999999.99;
