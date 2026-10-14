# Flex tracker import

The gig shift form previews completed MMIT Work Tracker v2-schema JSON exports for Lyft, Uber and Amazon Flex. The existing v0.7.0 and v0.7.1 Android exports contain enough information; no Android update is required.

Preview does not save a shift. For an outing already entered manually, open its **Edit** form before uploading so the evidence attaches to that row. Duplicate outing IDs remain subject to the existing unique tracker link and Accounting locks.

## Flex field mapping

| OPS field | Export source |
| --- | --- |
| Service | `shift.platform` |
| Date and departure/work start | `shift.startedAtEpochMs`, Fort Wayne local time |
| Actual finish | `shift.wentOfflineAtEpochMs`, validated against Flex actual finish and outcome event |
| Block hours and scheduled start/finish | `flexBlock.scheduledStartEpochMs`, `scheduledEndEpochMs` |
| Station | `flexBlock.station` |
| Start / offline / ending odometers | Corresponding `shift` readings |
| Odometer scope | Entire recorded outing |
| Work minutes | Departure through actual finish, excluding recorded breaks |
| Return minutes | Actual finish through arrival or corrected arrival |
| Earnings | Confirmed payout if present; otherwise offered pay for completed/no-route outings, zero for canceled/pulled outings |
| Notes | Export schedule, outcome and pay provenance; cancellation and closeout notes |

The vehicle, costs, package/stop counts and payout destination remain reviewable. Unknown package counts are not fabricated. Existing manual earnings are preserved when an export contains only offered pay. Offered pay is not verified Amazon payment; review actual earnings before Accounting posting.

## Personal detours

The original export includes every recorded return mile and minute. The importer does not infer a direct route or automatically exclude personal travel. To review a direct-home estimate, set the ending odometer scope to **Only to going offline / finishing work**, set the ending reading to the actual recorded offline reading, and enter estimated return miles/minutes separately. Explain the adjustment in the tracker correction note. The original final odometer remains in immutable evidence; do not invent a hypothetical vehicle reading.

Example: 95 miles before offline plus a reviewed 47-mile/47-minute direct-home estimate equals 142 outing miles and 276 rounded door-to-door minutes. The full actual 187-mile recording is retained as evidence.

## Verification and deployment

Run `php scripts/test_gig_tracker_import.php` under PHP 8.1 or later. The database-free tests cover mileage scope, timezone conversion, Flex outcomes and payouts, required returns, prefill preservation, and invalid inputs. Fixtures contain no production GPS coordinates.

The publishing helper requires an exact reviewed commit SHA, verifies the three live-file hashes, syntax-checks and runs tests before changing live files, backs up only those files, and restores them if installation fails. It does not import or modify shift records, run migrations, merge Git branches, or post to Accounting. Review the live import preview before saving an outing.
