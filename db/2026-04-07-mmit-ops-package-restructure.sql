-- MMIT Ops package/add-on restructure
-- Run this once against the ops database after backing it up.

START TRANSACTION;

-- Refresh bundle descriptions
UPDATE service_bundle
SET description = 'Remote monitoring, patching, managed endpoint protection, remote helpdesk, asset tracking, client portal access, and monthly health visibility for small environments that need reliable day-to-day support on a per-device basis.'
WHERE bundle_id = 1;

UPDATE service_bundle
SET description = 'Everything in Essential plus Huntress managed EDR, DNS filtering, enhanced security monitoring, and endpoint hardening for a stronger endpoint protection layer, billed per covered device.'
WHERE bundle_id = 2;

UPDATE service_bundle
SET description = 'Everything in Secure plus endpoint backup and recovery, device encryption management, hardening and privilege review, recovery readiness checks, and recurring security posture reporting for a higher-assurance managed security tier billed per covered device.'
WHERE bundle_id = 3;

-- Refresh base bundle item descriptions
UPDATE service_item
SET description = 'Base billable line for Essential IT managed services, billed per covered device.'
WHERE item_id = 8;

UPDATE service_item
SET description = 'Base billable line for Secure IT managed services, billed per covered device.'
WHERE item_id = 9;

UPDATE service_item
SET description = 'Base billable line for Complete IT managed security and resilience services, billed per covered device.'
WHERE item_id = 10;

-- Modernize core service naming for mixed Windows / Mac environments
UPDATE service_item
SET item_name = 'Managed Endpoint Protection',
    description = 'Managed endpoint protection administered through Syncro policies. Windows devices can use Microsoft Defender by default, while supported Apple devices can follow the platform-appropriate protection baseline.',
    pricing_model = 'PER_DEVICE'
WHERE item_id = 16;

UPDATE service_item
SET item_name = 'Enhanced Security Monitoring & Escalation Support',
    description = 'Heightened security monitoring, alert review, and escalation support for managed endpoints and security tooling.',
    pricing_model = 'FIXED',
    category_id = 3
WHERE item_id = 23;

-- Repurpose Microsoft 365 admin into a cloud security companion pack
UPDATE service_item
SET item_code = 'MS-365',
    item_name = 'Microsoft 365 Secure User',
    description = 'Per-user Microsoft 365 companion service covering email security, identity protection guidance, and day-to-day cloud user security support for managed tenants.',
    default_unit_price = 15.00,
    pricing_model = 'PER_USER',
    billing_mode = 'RECURRING',
    default_billing_cycle = 'MONTHLY',
    term_months = 12,
    category_id = 5,
    is_active = 1
WHERE item_id = 22;

-- Repurpose the legacy extra-admin item into Google Workspace companion coverage
UPDATE service_item
SET item_code = 'GW-SEC',
    item_name = 'Google Workspace Secure User',
    description = 'Per-user Google Workspace companion service covering email security, identity protection guidance, and day-to-day cloud user security support for managed tenants.',
    default_unit_price = 15.00,
    pricing_model = 'PER_USER',
    billing_mode = 'RECURRING',
    default_billing_cycle = 'MONTHLY',
    term_months = 12,
    category_id = 5,
    is_active = 1
WHERE item_id = 29;

-- Add new cloud / network add-ons
INSERT INTO service_item (
    item_code, item_name, item_type, description, default_unit_price, pricing_model, billing_mode,
    default_billing_cycle, term_months, revenue_account_id, category_id, is_taxable, is_active
)
VALUES
    ('GW-BKUP', 'Google Workspace Backup', 'SERVICE', 'Tenant backup coverage for Google Workspace email, drive data, and core cloud data.', 6.00, 'PER_USER', 'RECURRING', 'MONTHLY', 12, 12, 4, 0, 1),
    ('FW-NETSEC', 'Managed Firewall / Network Security', 'SERVICE', 'Managed firewall, VPN, and network security oversight for a supported site or firewall. Treat this as a flat recurring fee per site / firewall.', 0.00, 'FIXED', 'RECURRING', 'MONTHLY', 12, 12, 6, 0, 1)
ON DUPLICATE KEY UPDATE
    item_name = VALUES(item_name),
    description = VALUES(description),
    default_unit_price = VALUES(default_unit_price),
    pricing_model = VALUES(pricing_model),
    billing_mode = VALUES(billing_mode),
    default_billing_cycle = VALUES(default_billing_cycle),
    term_months = VALUES(term_months),
    revenue_account_id = VALUES(revenue_account_id),
    category_id = VALUES(category_id),
    is_taxable = VALUES(is_taxable),
    is_active = VALUES(is_active);

-- Rebuild bundle composition to match the new structure
DELETE FROM service_bundle_item WHERE bundle_id IN (1, 2, 3);

INSERT INTO service_bundle_item (bundle_id, item_id, item_role, default_quantity, override_unit_price, sort_order)
SELECT 1, 8, 'REQUIRED', 1.00, NULL, 10
UNION ALL SELECT 1, 12, 'INCLUDED', 1.00, 0.00, 20
UNION ALL SELECT 1, 13, 'INCLUDED', 1.00, 0.00, 30
UNION ALL SELECT 1, 16, 'INCLUDED', 1.00, 0.00, 40
UNION ALL SELECT 1, 11, 'INCLUDED', 1.00, 0.00, 50
UNION ALL SELECT 1, 14, 'INCLUDED', 1.00, 0.00, 60
UNION ALL SELECT 1, 15, 'INCLUDED', 1.00, 0.00, 70
UNION ALL SELECT 1, 19, 'ADDON_OPTION', 1.00, NULL, 200
UNION ALL SELECT 1, 24, 'ADDON_OPTION', 1.00, NULL, 210
UNION ALL SELECT 1, 22, 'ADDON_OPTION', 1.00, NULL, 220
UNION ALL SELECT 1, 29, 'ADDON_OPTION', 1.00, NULL, 230
UNION ALL SELECT 1, (SELECT item_id FROM service_item WHERE item_code = 'M365-BKUP' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 240
UNION ALL SELECT 1, (SELECT item_id FROM service_item WHERE item_code = 'GW-BKUP' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 250
UNION ALL SELECT 1, (SELECT item_id FROM service_item WHERE item_code = 'HUNT-ITDR' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 260
UNION ALL SELECT 1, (SELECT item_id FROM service_item WHERE item_code = 'SAT-TRAIN' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 270
UNION ALL SELECT 1, (SELECT item_id FROM service_item WHERE item_code = 'FW-NETSEC' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 280;

INSERT INTO service_bundle_item (bundle_id, item_id, item_role, default_quantity, override_unit_price, sort_order)
SELECT 2, 9, 'REQUIRED', 1.00, NULL, 10
UNION ALL SELECT 2, 12, 'INCLUDED', 1.00, 0.00, 20
UNION ALL SELECT 2, 13, 'INCLUDED', 1.00, 0.00, 30
UNION ALL SELECT 2, 16, 'INCLUDED', 1.00, 0.00, 40
UNION ALL SELECT 2, 11, 'INCLUDED', 1.00, 0.00, 50
UNION ALL SELECT 2, 14, 'INCLUDED', 1.00, 0.00, 60
UNION ALL SELECT 2, 15, 'INCLUDED', 1.00, 0.00, 70
UNION ALL SELECT 2, 21, 'INCLUDED', 1.00, 0.00, 80
UNION ALL SELECT 2, 17, 'INCLUDED', 1.00, 0.00, 90
UNION ALL SELECT 2, 23, 'INCLUDED', 1.00, 0.00, 100
UNION ALL SELECT 2, 19, 'ADDON_OPTION', 1.00, NULL, 200
UNION ALL SELECT 2, 24, 'ADDON_OPTION', 1.00, NULL, 210
UNION ALL SELECT 2, 22, 'ADDON_OPTION', 1.00, NULL, 220
UNION ALL SELECT 2, 29, 'ADDON_OPTION', 1.00, NULL, 230
UNION ALL SELECT 2, (SELECT item_id FROM service_item WHERE item_code = 'M365-BKUP' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 240
UNION ALL SELECT 2, (SELECT item_id FROM service_item WHERE item_code = 'GW-BKUP' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 250
UNION ALL SELECT 2, (SELECT item_id FROM service_item WHERE item_code = 'HUNT-ITDR' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 260
UNION ALL SELECT 2, (SELECT item_id FROM service_item WHERE item_code = 'SAT-TRAIN' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 270
UNION ALL SELECT 2, (SELECT item_id FROM service_item WHERE item_code = 'FW-NETSEC' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 280;

INSERT INTO service_bundle_item (bundle_id, item_id, item_role, default_quantity, override_unit_price, sort_order)
SELECT 3, 10, 'REQUIRED', 1.00, NULL, 10
UNION ALL SELECT 3, 12, 'INCLUDED', 1.00, 0.00, 20
UNION ALL SELECT 3, 13, 'INCLUDED', 1.00, 0.00, 30
UNION ALL SELECT 3, 16, 'INCLUDED', 1.00, 0.00, 40
UNION ALL SELECT 3, 11, 'INCLUDED', 1.00, 0.00, 50
UNION ALL SELECT 3, 14, 'INCLUDED', 1.00, 0.00, 60
UNION ALL SELECT 3, 15, 'INCLUDED', 1.00, 0.00, 70
UNION ALL SELECT 3, 21, 'INCLUDED', 1.00, 0.00, 80
UNION ALL SELECT 3, 17, 'INCLUDED', 1.00, 0.00, 90
UNION ALL SELECT 3, 23, 'INCLUDED', 1.00, 0.00, 100
UNION ALL SELECT 3, 19, 'INCLUDED', 1.00, 0.00, 110
UNION ALL SELECT 3, 24, 'ADDON_OPTION', 1.00, NULL, 210
UNION ALL SELECT 3, 22, 'ADDON_OPTION', 1.00, NULL, 220
UNION ALL SELECT 3, 29, 'ADDON_OPTION', 1.00, NULL, 230
UNION ALL SELECT 3, (SELECT item_id FROM service_item WHERE item_code = 'M365-BKUP' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 240
UNION ALL SELECT 3, (SELECT item_id FROM service_item WHERE item_code = 'GW-BKUP' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 250
UNION ALL SELECT 3, (SELECT item_id FROM service_item WHERE item_code = 'HUNT-ITDR' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 260
UNION ALL SELECT 3, (SELECT item_id FROM service_item WHERE item_code = 'SAT-TRAIN' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 270
UNION ALL SELECT 3, (SELECT item_id FROM service_item WHERE item_code = 'FW-NETSEC' LIMIT 1), 'ADDON_OPTION', 1.00, NULL, 280;

COMMIT;
