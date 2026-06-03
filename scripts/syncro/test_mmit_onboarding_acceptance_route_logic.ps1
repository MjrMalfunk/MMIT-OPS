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
}
$protectDecision = Get-MMITOnboardingDecision -CustomFields $protectFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'FAIL'; CoveAgent = 'PASS'; CoveBackupComplete = 'PASS' }
Assert-Equal $protectDecision.Status 'NOT_READY' 'Protect IT workstation status'
Assert-Equal ($protectDecision.Failures -contains 'Huntress') $true 'Protect IT workstation Huntress failure included'
Assert-Equal $protectDecision.Route.Requirements.CoveAgent.Required $true 'Protect IT workstation Cove required'

$serverFields = @{
    'MMIT Service Tier' = 'Protect IT'
    'MMIT Asset Role' = 'Server'
    'MMIT Backup Required' = $false
}
$serverDecision = Get-MMITOnboardingDecision -CustomFields $serverFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'PASS'; Huntress = 'PASS'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $serverDecision.Status 'READY' 'Protect IT server with no backup status'
Assert-Equal $serverDecision.Route.Requirements.CoveAgent.Required $false 'Protect IT server Cove required only when backup selected'
Assert-Equal $serverDecision.Route.Requirements.ScoutDNS.Required $true 'Protect IT server ScoutDNS required'

$manageDnsFields = @{
    'MMIT Service Tier' = 'Manage IT'
    'MMIT Asset Role' = 'Workstation'
    'MMIT Backup Required' = 'false'
    'MMIT DNS Filtering Required' = 'true'
}
$manageDnsDecision = Get-MMITOnboardingDecision -CustomFields $manageDnsFields -CheckResults @{ Defender = 'PASS'; ScoutDNS = 'FAIL'; Huntress = 'FAIL'; CoveAgent = 'FAIL'; CoveBackupComplete = 'FAIL' }
Assert-Equal $manageDnsDecision.Status 'NOT_READY' 'Manage IT workstation with DNS selected status'
Assert-Equal ($manageDnsDecision.Failures -contains 'ScoutDNS') $true 'Manage IT workstation DNS failure included'
Assert-Equal ($manageDnsDecision.Failures -contains 'Huntress') $false 'Skipped Huntress excluded from failures'
Assert-Equal ($manageDnsDecision.Failures -contains 'CoveAgent') $false 'Skipped Cove excluded from failures'

Write-Output 'MMIT onboarding acceptance route logic tests passed.'
