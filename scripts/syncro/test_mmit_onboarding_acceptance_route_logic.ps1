Set-StrictMode -Version 2.0
. "$PSScriptRoot/MMIT-Onboarding-Acceptance.ps1"

function Assert-Equal {
    param(
        [Parameter(Mandatory = $true)][object]$Actual,
        [Parameter(Mandatory = $true)][object]$Expected,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($Actual -ne $Expected) {
        throw ("{0}: expected '{1}', got '{2}'" -f $Label, $Expected, $Actual)
    }
}

$manageLabFields = @{
    'MMIT Service Tier' = 'Manage IT'
    'MMIT Asset Role' = 'Workstation'
    'MMIT Backup Required' = $false
    'MMIT Lab Asset' = $true
    'MMIT Production Folder Target' = 'Production/Workstations'
    'MMIT Onboarding Status' = 'NOT_READY'
    'MMIT Ready To Move' = 'No'
}

$manageLabChecks = @{
    Defender = 'PASS'
    ScoutDNS = 'PASS'
    Huntress = 'FAIL'
    CoveAgent = 'FAIL'
    CoveBackupComplete = 'FAIL'
}

$manageDecision = Get-MMITOnboardingDecision -CustomFields $manageLabFields -CheckResults $manageLabChecks
Assert-Equal $manageDecision.Status 'READY' 'Manage IT workstation status'
Assert-Equal $manageDecision.ReadyToMove 'Yes' 'Manage IT workstation ready to move'
Assert-Equal $manageDecision.Failures.Count 0 'Manage IT workstation failures'
Assert-Equal $manageDecision.Route.Requirements.Huntress.Required $false 'Manage IT workstation Huntress required'
Assert-Equal $manageDecision.Route.Requirements.ScoutDNS.Required $false 'Manage IT workstation ScoutDNS required without DNS field'
Assert-Equal $manageDecision.Route.Requirements.CoveAgent.Required $false 'Manage IT workstation Cove required'

$protectFields = @{
    'MMIT Service Tier' = 'Protect IT'
    'MMIT Asset Role' = 'Workstation'
    'MMIT Backup Required' = $false
    'MMIT Production Folder Target' = 'Production/Workstations'
}
$protectDecision = Get-MMITOnboardingDecision -CustomFields $protectFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'FAIL'; CoveAgent = 'PASS'; CoveBackupComplete = 'PASS' }
Assert-Equal $protectDecision.Status 'NOT_READY' 'Protect IT workstation status'
Assert-Equal ($protectDecision.Failures -contains 'Huntress') $true 'Protect IT workstation Huntress failure included'
Assert-Equal $protectDecision.Route.Requirements.CoveAgent.Required $true 'Protect IT workstation Cove required'

$serverFields = @{
    'MMIT Service Tier' = 'Protect IT'
    'MMIT Asset Role' = 'Server'
    'MMIT Backup Required' = $false
    'MMIT Production Folder Target' = 'Production/Servers'
}
$serverDecision = Get-MMITOnboardingDecision -CustomFields $serverFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'PASS'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $serverDecision.Status 'READY' 'Protect IT server with no backup status'
Assert-Equal $serverDecision.Route.Requirements.CoveAgent.Required $false 'Protect IT server Cove required only when backup selected'
Assert-Equal $serverDecision.Route.Requirements.ScoutDNS.Required $false 'Protect IT server ScoutDNS skipped'

$manageDnsFields = @{
    'MMIT Service Tier' = 'Manage IT'
    'MMIT Asset Role' = 'Workstation'
    'MMIT Backup Required' = 'false'
    'MMIT DNS Filtering Required' = 'true'
    'MMIT Production Folder Target' = 'Production/Workstations'
}
$manageDnsDecision = Get-MMITOnboardingDecision -CustomFields $manageDnsFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'FAIL'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $manageDnsDecision.Status 'NOT_READY' 'Manage IT workstation with DNS selected status'
Assert-Equal ($manageDnsDecision.Failures -contains 'ScoutDNS') $true 'Manage IT workstation DNS failure included'
Assert-Equal ($manageDnsDecision.Failures -contains 'Huntress') $false 'Skipped Huntress excluded from failures'
Assert-Equal ($manageDnsDecision.Failures -contains 'CoveAgent') $false 'Skipped Cove excluded from failures'


$aliasFields = @{
    'MMIT Service Tier' = 'Manage'
    'MMIT Asset Role' = 'Workstations'
    'MMIT Backup Required' = $false
    'MMIT Production Folder Target' = 'Production/Workstations'
}
$aliasDecision = Get-MMITOnboardingDecision -CustomFields $aliasFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'FAIL'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $aliasDecision.Status 'READY' 'Manage/Workstations alias status'
Assert-Equal $aliasDecision.Route.Requirements.ScoutDNS.Required $false 'Manage alias ScoutDNS not required without DNS field'
Assert-Equal $aliasDecision.Route.Requirements.Huntress.Required $false 'Manage alias Huntress not required'

$protectAliasFields = @{
    'MMIT Service Tier' = 'Protect'
    'MMIT Asset Role' = 'Servers'
    'MMIT Backup Required' = $true
    'MMIT Production Folder Target' = 'Production/Servers'
}
$protectAliasDecision = Get-MMITOnboardingDecision -CustomFields $protectAliasFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'PASS'; CoveAgent = 'PASS'; CoveBackupComplete = 'PASS' }
Assert-Equal $protectAliasDecision.Status 'READY' 'Protect/Servers alias status'
Assert-Equal $protectAliasDecision.Route.Requirements.Huntress.Required $true 'Protect alias Huntress required'
Assert-Equal $protectAliasDecision.Route.Requirements.CoveAgent.Required $true 'Servers alias Cove required when backup selected'

$governAliasFields = @{
    'MMIT Service Tier' = 'Govern'
    'MMIT Asset Role' = 'Server'
    'MMIT Backup Required' = $false
    'MMIT Production Folder Target' = 'Production/Servers'
}
$governAliasDecision = Get-MMITOnboardingDecision -CustomFields $governAliasFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'PASS'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $governAliasDecision.Status 'READY' 'Govern alias server no-backup status'
Assert-Equal $governAliasDecision.Route.Requirements.ScoutDNS.Required $false 'Govern alias server ScoutDNS skipped'
Assert-Equal $governAliasDecision.Route.Requirements.CoveAgent.Required $false 'Server alias Cove skipped when backup not selected'

$missingRouteFields = @{
    'MMIT Backup Required' = $false
    'MMIT DNS Filtering Required' = $true
}
$missingRouteDecision = Get-MMITOnboardingDecision -CustomFields $missingRouteFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $missingRouteDecision.Status 'NOT_READY' 'Missing route values status'
Assert-Equal $missingRouteDecision.ReadyToMove 'No' 'Missing route values ready to move'
Assert-Equal ($missingRouteDecision.Failures -contains 'ServiceTier') $false 'Missing route ServiceTier failure excluded'
Assert-Equal ($missingRouteDecision.Failures -contains 'AssetRole') $true 'Missing route AssetRole failure included'
Assert-Equal ($missingRouteDecision.Summary -like '*Service Tier missing; manual label recommended; not blocking movement.*') $true 'Missing ServiceTier warning summary line'
Assert-Equal ($missingRouteDecision.Summary -like '*Route validation: FAIL - missing AssetRole*') $true 'Missing AssetRole summary line'

$invalidRouteFields = @{
    'MMIT Service Tier' = 'Unknown IT'
    'MMIT Asset Role' = 'Kiosk'
    'MMIT Backup Required' = $false
}
$invalidRouteDecision = Get-MMITOnboardingDecision -CustomFields $invalidRouteFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $invalidRouteDecision.Status 'NOT_READY' 'Invalid route values status'
Assert-Equal ($invalidRouteDecision.Summary -like "*Service Tier label warning: unrecognized ServiceTier 'Unknown IT'; not blocking movement.*") $true 'Invalid ServiceTier warning summary line'
Assert-Equal ($invalidRouteDecision.Summary -like "*Route validation: FAIL - unrecognized AssetRole 'Kiosk'*") $true 'Invalid AssetRole summary line'

$manageDnsReadyFields = @{
    'MMIT Service Tier' = 'Manage IT'
    'MMIT Asset Role' = 'Workstation'
    'MMIT Backup Required' = $false
    'MMIT DNS Filtering Required' = $true
    'MMIT Production Folder Target' = 'Production/Workstations'
}
$manageDnsReadyDecision = Get-MMITOnboardingDecision -CustomFields $manageDnsReadyFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $manageDnsReadyDecision.Status 'READY' 'Manage IT workstation with DNS selected and ScoutDNS passing status'
Assert-Equal $manageDnsReadyDecision.Route.Requirements.ScoutDNS.Required $true 'Manage IT DNS selected ScoutDNS required'
Assert-Equal $manageDnsReadyDecision.Route.Requirements.Huntress.Required $false 'Manage IT DNS selected Huntress skipped'
Assert-Equal $manageDnsReadyDecision.Route.Requirements.CoveAgent.Required $false 'Manage IT DNS selected Cove skipped'
Assert-Equal ($manageDnsReadyDecision.Summary -like '*Route validation: PASS*') $true 'Valid route validation pass line'


$missingTargetFields = @{
    'MMIT Service Tier' = 'Manage IT'
    'MMIT Asset Role' = 'Workstation'
    'MMIT Backup Required' = $false
}
$missingTargetDecision = Get-MMITOnboardingDecision -CustomFields $missingTargetFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $missingTargetDecision.Status 'NOT_READY' 'Missing production folder target status'
Assert-Equal ($missingTargetDecision.Failures -contains 'ProductionFolderTarget') $true 'Missing ProductionFolderTarget failure included'
Assert-Equal ($missingTargetDecision.Summary -like '*Route validation: FAIL - missing ProductionFolderTarget; expected Production/Workstations*') $true 'Missing ProductionFolderTarget summary line'

$mismatchedTargetFields = @{
    'MMIT Service Tier' = 'Protect IT'
    'MMIT Asset Role' = 'Server'
    'MMIT Backup Required' = $false
    'MMIT Production Folder Target' = 'Production/Workstations'
}
$mismatchedTargetDecision = Get-MMITOnboardingDecision -CustomFields $mismatchedTargetFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'PASS'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $mismatchedTargetDecision.Status 'NOT_READY' 'Mismatched production folder target status'
Assert-Equal ($mismatchedTargetDecision.Failures -contains 'ProductionFolderTarget') $true 'Mismatched ProductionFolderTarget failure included'
Assert-Equal ($mismatchedTargetDecision.Summary -like "*Route validation: FAIL - ProductionFolderTarget 'Production/Workstations' does not match expected Production/Servers*") $true 'Mismatched ProductionFolderTarget summary line'

function Get-ItemProperty {
    param(
        [string]$Path,
        [string]$ErrorAction
    )

    return @(
        [pscustomobject]@{ Publisher = 'Missing DisplayName Corp' },
        [pscustomobject]@{ DisplayName = 'ScoutDNS Agent' }
    )
}

Assert-Equal (Test-MMITInstalledProgram -NamePatterns @('*ScoutDNS*')) $true 'Installed program skips items without DisplayName under strict mode'

Write-Output 'MMIT onboarding acceptance route logic tests passed.'
