# Syncro Finalize Ready Workstations admin runner

`automation/syncro/Finalize-Ready-Workstations.ps1` is an MMIT admin workstation runner for finalizing READY workstation assets in Syncro. It is not a client endpoint script and must only run from trusted MMIT automation hosts included in the script's host allowlist.

Operational notes:

- Keep `SYNCRO_API_TOKEN` configured as a Machine environment variable on trusted MMIT automation hosts only.
- Do not add API tokens or secrets to the script or documentation.
- The script defaults to dry-run mode; pass `-DryRun $false` only after confirming the runner host, lock state, and candidate logs.
- The workstation lane expects `MMIT Production Folder Target` to be `Production/Workstations` and uses Syncro `PUT /api/v1/customer_assets/{asset_id}` with `policy_folder_id` for the folder move.
- Server folder IDs are present for design parity, but server moves remain disabled/dry-run-only until explicitly implemented in a separate change.
- MMIT onboarding acceptance stores the created ready/move ticket reference on the asset in `MMIT Ready Move Ticket ID` (with `MMIT Auto Move Ticket ID` also recognized by the mover for compatibility). Newer acceptance responses prefer Syncro internal ticket IDs when available, but older/staged values may be visible ticket numbers.
- After successful post-move verification, the runner writes `MMIT Auto Move Result` and `MMIT Onboarding Completed At`, then reads the stored ready/move ticket reference from the asset and tries to add a private completion note with subject `MMIT Auto Move Result` via Syncro `POST /api/v1/tickets/{ticket_id}/comment` using top-level `subject`, `body`, `hidden = true`, and `do_not_email = true` fields. If Syncro returns 404 because the stored value is a visible ticket number rather than an internal API ticket ID, the runner searches tickets by that visible number/reference, prefers internal ID fields such as `id`, `ticket_id`, or `internal_id`, and posts the note to the resolved internal ID. The legacy open-ticket search is fallback only when no stored ticket reference is present. It does not resolve or close the ticket; manual technician verification and closure are still required. Ticket-note failures are warning-only and keep the verified move successful.

Suggested syntax/static smoke check on a Windows admin runner with PowerShell available:

```powershell
pwsh -NoProfile -File scripts/syncro/Test-FinalizeReadyWorkstationsSyntax.ps1
```
