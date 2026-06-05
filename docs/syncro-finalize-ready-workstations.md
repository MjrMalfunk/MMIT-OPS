# Syncro Finalize Ready Workstations admin runner

`automation/syncro/Finalize-Ready-Workstations.ps1` is an MMIT admin workstation runner for finalizing READY workstation assets in Syncro. It is not a client endpoint script and must only run from trusted MMIT automation hosts included in the script's host allowlist.

Operational notes:

- Keep `SYNCRO_API_TOKEN` configured as a Machine environment variable on trusted MMIT automation hosts only.
- Do not add API tokens or secrets to the script or documentation.
- The script defaults to dry-run mode; pass `-DryRun $false` only after confirming the runner host, lock state, and candidate logs.
- The workstation lane expects `MMIT Production Folder Target` to be `Production/Workstations` and uses Syncro `PUT /api/v1/customer_assets/{asset_id}` with `policy_folder_id` for the folder move.
- Server folder IDs are present for design parity, but server moves remain disabled/dry-run-only until explicitly implemented in a separate change.
- Ticket comments, status changes, and closures are intentionally not handled by this runner.

Suggested syntax/static smoke check on a Windows admin runner with PowerShell available:

```powershell
pwsh -NoProfile -File scripts/syncro/Test-FinalizeReadyWorkstationsSyntax.ps1
```
