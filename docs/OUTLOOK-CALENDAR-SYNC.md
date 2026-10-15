# OPS Outlook calendar sync

OPS can add scheduled FieldNation work orders and accepted Amazon Flex blocks to the signed-in Microsoft 365 user's Outlook calendar.

## Microsoft app registration

In Microsoft Entra admin center, open the app registration already used by OPS OneDrive. Under **API permissions**, add Microsoft Graph **Delegated** permission `Calendars.ReadWrite`. No app secret or new redirect URI is needed. The user grants this permission during the OPS calendar connection flow. Tenant policy may still require an administrator to approve user consent.

The normal OneDrive connection continues requesting only `offline_access`, `Files.ReadWrite`, and `User.Read`. Calendar access is requested only by the dedicated button on **Field Ops → Outlook calendar**. The OAuth callback persists the granted scopes with the encrypted token and refreshes with the same scope set.

## Behavior

- Applying an assigned FieldNation email or saving a FieldNation work order with an assigned/scheduled status and complete start/end times syncs its private, busy Outlook event.
- Later schedule/status saves update the linked event. Cancelling, declining, or discarding the work order removes the linked event. OPS saves remain successful if Graph is unavailable; the Calendar page records the sync error and provides retry.
- The event copies the reserved window stored in OPS exactly. Add drive and contingency time to the OPS schedule when needed; calendar sync does not add another buffer.
- **Field Ops → Outlook calendar** provides a one-time sync action for existing scheduled work orders. Do not use it on jobs already entered manually in Outlook, because OPS cannot identify or update those manual events.
- The same page provides a manual Flex block form. Enter the accepted block's start and end; OPS creates a private, busy event with a 30-minute reminder. Flex does not expose an OPS-supported schedule feed in this integration.
- Creation uses a stable Graph `transactionId`; updates use the stored Graph event ID. Each source record is linked once in `field_outlook_calendar_events`.

The `field_outlook_calendar_events` table is created by the OPS schema helper on first use. There is no backfill, cron job, or external-calendar scan.

## Verification

Run `php scripts/test_outlook_calendar.php` and lint the changed PHP files before release. After deploying, connect Outlook Calendar from OPS, sync one scheduled work order that is not already on the calendar, update its schedule to verify the existing Outlook event moves, and use a test Flex block to verify creation and removal.
