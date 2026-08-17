# Syncro offboarding and archived mode

## Purpose

OPS retains its Syncro integration code and all historical Syncro identifiers, but `SYNCRO_ENABLED=false` makes Syncro an archived dependency. Archived mode blocks Syncro API traffic, skips Syncro cron work, removes Syncro-only onboarding requirements, and hides write actions in the OPS interface.

The flag is backward compatible: if `SYNCRO_ENABLED` is absent, Syncro remains enabled. Deploy and test the code before adding the flag to a private environment configuration.

## Preserved configuration snapshot

The private workstation archive created on 2026-08-17 contains:

- 61 scripts with source; no empty script sources
- 28 policy forms
- 5 policy-module lists
- 82 captured schedule rows and 301 rendered policy values
- 2 Syncro Script Files (`cove#v1#f77977d4-a053-db26-31bc-22d24f911870#.exe` and `MMIT-Scout-Protect.msi`)
- Primary and policy-supplement JSON files, expanded copies, export tools, and `SHA256-MANIFEST.csv`

The archive is `MMIT-Syncro-Offboarding-20260817` and must remain confidential. Script source and installer command lines may contain credentials or customer-specific values.

Process and Service Monitoring contained no custom modules. Event Log Monitoring contained no custom MMIT policies. Syncro's built-in `Preset - Server Network Services` displayed nine global policy associations, while the complete captured MMIT policy forms contained these six direct references:

- `MMIT-Govern-Servers`
- `MMIT-Manage-Servers`
- `MMIT-Protect-Servers`
- `MMIT-TEST-Govern-Servers`
- `MMIT-TEST-Manage-Servers`
- `MMIT-TEST-Protect-Servers`

The three-count difference is documented as a Syncro internal, inherited, or stale association discrepancy. It is not missing custom MMIT content.

## Activation order

1. Deploy the archived-mode code to OPS TEST without defining `SYNCRO_ENABLED`. Confirm normal behavior is unchanged.
2. Add `define('SYNCRO_ENABLED', false);` to `/home/mjrmstlj/private/ops/secrets.staging.php`.
3. Run syntax checks and the Syncro smoke suite. Confirm the master dispatcher skips only its two Syncro jobs and still attempts the Field Ops FN radar job.
4. Verify client and contract pages retain historical Syncro IDs/statuses but expose no Syncro write controls.
5. Deploy the verified commit to OPS LIVE.
6. Add `define('SYNCRO_ENABLED', false);` to `/home/mjrmstlj/private/ops/secrets.php`.
7. Repeat live read-only verification before cancelling the subscription.

## Required verification

Use the server's PHP 8.1 binary:

```bash
PHP=/opt/cpanel/ea-php81/root/usr/bin/php

git ls-files '*.php' | while IFS= read -r file; do
    "$PHP" -l "$file"
done

for smoke in \
    scripts/smoke_syncro_staging_guard.php \
    scripts/smoke_syncro_folder_map.php \
    scripts/smoke_syncro_root_asset_intake_router.php \
    scripts/smoke_syncro_root_asset_intake_cron.php \
    scripts/smoke_syncro_auto_move_ready_assets.php \
    scripts/smoke_syncro_production_mover.php \
    scripts/smoke_syncro_onboarding_completion.php \
    scripts/smoke_syncro_archived_mode.php
do
    "$PHP" -d display_errors=1 "$smoke"
done
```

With the environment flag disabled, verify the dispatcher:

```bash
PHP=/opt/cpanel/ea-php81/root/usr/bin/php

"$PHP" -d display_errors=1 scripts/ops_automation_cron.php \
    --host=ops-test.midwestmanagedit.com \
    --force
```

Expected Syncro lines contain `ARCHIVED/DISABLED` and `skipped`. The Field Ops FN radar remains an independent task; its existing IMAP authentication failure must be repaired separately.

## Subscription cancellation checklist

- Keep two copies of `MMIT-Syncro-Offboarding-20260817`, with at least one copy outside the Downloads folder.
- Re-run `SHA256-MANIFEST.csv` verification after copying the archive.
- Record the subscription cancellation date and effective service-end date.
- On Keith's and Doc's real computers, remove only the Syncro RMM agent before service ends. Do not remove Huntress, Cove, ScoutDNS, Microsoft Defender, or another independently licensed security agent merely because Syncro is being cancelled.
- Confirm no other real endpoint remains in Syncro; test assets and VMs do not need preservation.
- Disable the Syncro MCP connector and revoke its OAuth grants if it was ever enabled.
- Revoke or rotate the Syncro API credential after archived mode is verified live. Retain the subdomain and non-secret mapping information in private documentation.
- Do not delete OPS client, contract, folder-map, or historical status fields.
- Keep the Syncro code in Git so the integration can be restored or adapted to another RMM later.

## Restore

Restoring the old integration requires an active Syncro account, a valid least-privilege credential, and an explicit `SYNCRO_ENABLED=true` in the private environment configuration. Re-run all Syncro smoke tests before allowing the master cron to resume Syncro jobs. Historical policy IDs and folder IDs must be revalidated because a new or restored Syncro tenant may assign different identifiers.
