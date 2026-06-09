# Simple local syntax/smoke check for the Syncro admin runner.
# This test parses the PowerShell file only; it does not call Syncro and does not
# require or read SYNCRO_API_TOKEN.

$ErrorActionPreference = "Stop"

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$RunnerPath = Join-Path $RepoRoot "automation\syncro\Finalize-Ready-Workstations.ps1"

$ParseErrors = $null
$Tokens = $null
[System.Management.Automation.Language.Parser]::ParseFile($RunnerPath, [ref]$Tokens, [ref]$ParseErrors) | Out-Null

if ($ParseErrors -and $ParseErrors.Count -gt 0) {
    $ParseErrors | Format-List
    throw "Finalize-Ready-Workstations.ps1 contains PowerShell parser errors."
}

$Content = Get-Content -Path $RunnerPath -Raw
$RequiredText = @(
    '[bool]$DryRun = $true',
    'SYNCRO_API_TOKEN',
    '5027867',
    '5027864',
    '5027868',
    '5027865',
    '135355',
    '135359',
    'Production/Workstations',
    'MMIT Auto Move Result',
    'MMIT Onboarding Completed At',
    'Find-TicketByReference',
    'Get-TicketInternalId',
    'internal_id',
    'ticket_reference'
)

foreach ($Text in $RequiredText) {
    if (-not $Content.Contains($Text)) {
        throw "Expected runner text not found: $Text"
    }
}

if ($Content.Contains('$DryRun = $false')) {
    throw 'Runner must default to dry-run true.'
}

Write-Host "Finalize-Ready-Workstations.ps1 syntax/static smoke check passed."
