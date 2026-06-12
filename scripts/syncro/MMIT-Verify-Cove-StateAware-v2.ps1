# MMIT-Verify-Cove v2
# READ-ONLY: Route-aware, state-aware verification of Cove/N-able Backup Manager installation and service status.
#
# Behavior:
# - Skips when Cove is not required.
# - Writes state to C:\ProgramData\MMIT\Onboarding\Cove-verify.json.
# - If a previous HEALTHY state exists for the same route, performs a light live check only.
# - If the light live check passes, exits 0 as ALREADY_HEALTHY.
# - If no valid healthy state exists, performs full verification.
#
# Run as: System
# Recommended Syncro schedule while in Deploy: if not run in past 4-6 hours

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Continue"

$Component = "Cove"
$StateDir = "C:\ProgramData\MMIT\Onboarding"
$LogDir = "C:\ProgramData\MMIT\Logs"
$StateFile = Join-Path $StateDir "$Component-verify.json"

New-Item -ItemType Directory -Path $StateDir -Force | Out-Null
New-Item -ItemType Directory -Path $LogDir -Force | Out-Null

Write-Host "==== MMIT Cove Verification v2 ===="
Write-Host "ComputerName: $env:COMPUTERNAME"
Write-Host "Timestamp: $(Get-Date)"
Write-Host "StateFile: $StateFile"

function ConvertTo-MMITText {
    param([AllowNull()][object]$Value)
    if ($null -eq $Value) { return '' }
    return ([string]$Value).Trim()
}

function ConvertTo-MMITRouteKey {
    param([AllowNull()][object]$Value)
    $text = (ConvertTo-MMITText $Value).ToLowerInvariant()
    $text = $text -replace '[^a-z0-9]+', ''
    return $text
}

function ConvertTo-MMITBoolean {
    param([AllowNull()][object]$Value)
    if ($null -eq $Value) { return $false }
    if ($Value -is [bool]) { return [bool]$Value }
    if ($Value -is [int] -or $Value -is [long]) { return ([int]$Value) -ne 0 }
    $text = (ConvertTo-MMITText $Value).ToLowerInvariant()
    return @('1', 'true', 'yes', 'y', 'checked', 'on', 'enabled', 'required') -contains $text
}

function Get-MMITRuntimeValue {
    param(
        [Parameter(Mandatory = $true)][string[]]$Names,
        [AllowNull()][object]$Default = ''
    )

    foreach ($name in $Names) {
        foreach ($scope in @('Script', 'Global')) {
            $variable = Get-Variable -Name $name -Scope $scope -ErrorAction SilentlyContinue
            if ($null -ne $variable -and (ConvertTo-MMITText $variable.Value) -ne '') {
                return $variable.Value
            }
        }

        $environmentValue = [Environment]::GetEnvironmentVariable($name)
        if ((ConvertTo-MMITText $environmentValue) -ne '') {
            return $environmentValue
        }
    }

    return $Default
}

function Get-MMITObjectPropertyValue {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()][object]$Default = $null
    )
    if ($null -eq $Object) { return $Default }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $Default }
    return $property.Value
}

function Get-MMITObjectPropertyText {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [string]$Default = ''
    )
    $value = Get-MMITObjectPropertyValue -Object $Object -Name $Name -Default $Default
    $text = ConvertTo-MMITText $value
    if ($text -eq '') { return $Default }
    return $text
}

function Read-MMITJsonFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    try {
        if (-not (Test-Path -LiteralPath $Path)) { return $null }
        $content = Get-Content -LiteralPath $Path -Raw -ErrorAction Stop
        if ((ConvertTo-MMITText $content) -eq '') { return $null }
        return ($content | ConvertFrom-Json -ErrorAction Stop)
    } catch {
        Write-Host "StateReadWarning: Could not read $Path - $($_.Exception.Message)"
        return $null
    }
}

function Write-MMITJsonFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][hashtable]$Data
    )
    try {
        $json = $Data | ConvertTo-Json -Depth 10
        Set-Content -LiteralPath $Path -Value $json -Encoding UTF8 -ErrorAction Stop
        Write-Host "StateWritten: $Path"
    } catch {
        Write-Host "StateWriteWarning: Could not write $Path - $($_.Exception.Message)"
    }
}

function Get-MMITCoveServices {
    $serviceCandidates = @(
        'Backup Service Controller',
        'BackupFP',
        'Backup Manager Service Controller',
        'N-able Backup Service Controller',
        'CoveBackup'
    )

    $services = @()
    foreach ($svcName in $serviceCandidates) {
        $svc = Get-Service -Name $svcName -ErrorAction SilentlyContinue
        if ($svc) { $services += $svc }
    }

    if ($services.Count -eq 0) {
        $services = @(Get-Service -ErrorAction SilentlyContinue | Where-Object {
            $_.Name -match 'Backup|Cove|N-able|Nable' -or $_.DisplayName -match 'Backup|Cove|N-able|Nable'
        })
    }

    return $services
}

function Test-MMITCoveInstallMarker {
    $paths = @(
        'C:\Program Files\Backup Manager',
        'C:\Program Files (x86)\Backup Manager',
        'C:\Program Files\N-able Backup',
        'C:\Program Files (x86)\N-able Backup'
    )

    foreach ($path in $paths) {
        if (Test-Path -LiteralPath $path) {
            Write-Host "InstallPathFound: $path"
            return $true
        }
    }

    return $false
}

function Invoke-MMITCoveCheck {
    param([Parameter(Mandatory = $true)][string]$Phase)

    Write-Host ""
    Write-Host "==== Cove Check: $Phase ===="

    $issues = @()
    $warnings = @()
    $serviceNames = @()
    $runningServiceNames = @()

    $services = @(Get-MMITCoveServices)
    foreach ($svc in $services) {
        $serviceNames += $svc.Name
        Write-Host ""
        Write-Host "Service Found: $($svc.Name)"
        Write-Host "DisplayName: $($svc.DisplayName)"
        Write-Host "Status: $($svc.Status)"
        if ($svc.PSObject.Properties.Name -contains 'StartType') {
            Write-Host "StartType: $($svc.StartType)"
        }
        if ($svc.Status -eq 'Running') {
            $runningServiceNames += $svc.Name
        } else {
            $issues += "$($svc.Name) is not running."
        }
    }

    if ($services.Count -eq 0) {
        $issues += 'No Cove/N-able Backup services were found.'
    }

    $installFound = Test-MMITCoveInstallMarker
    if (-not $installFound) {
        $issues += 'Cove/N-able Backup install folder not found.'
    }

    return [PSCustomObject]@{
        Phase = $Phase
        Issues = @($issues)
        Warnings = @($warnings)
        ServiceNames = @($serviceNames)
        RunningServiceNames = @($runningServiceNames)
        ServiceCount = $services.Count
        RunningServiceCount = $runningServiceNames.Count
        InstallFound = $installFound
    }
}

function Write-MMITCoveState {
    param(
        [Parameter(Mandatory = $true)][string]$Status,
        [Parameter(Mandatory = $true)][bool]$Required,
        [Parameter(Mandatory = $true)][string]$RouteFingerprint,
        [Parameter(Mandatory = $true)][object]$Route,
        [AllowNull()][object]$Check = $null,
        [string]$LastResult = '',
        [string]$Message = ''
    )

    $previous = Read-MMITJsonFile -Path $StateFile
    $previousHealthyAt = Get-MMITObjectPropertyText -Object $previous -Name 'lastHealthyAt'
    $now = (Get-Date).ToString('o')
    $lastHealthyAt = $previousHealthyAt
    if ($Status -eq 'HEALTHY') { $lastHealthyAt = $now }

    Write-MMITJsonFile -Path $StateFile -Data @{
        schemaVersion = 2
        component = $Component
        computerName = $env:COMPUTERNAME
        checkedAt = $now
        lastHealthyAt = $lastHealthyAt
        required = $Required
        status = $Status
        lastResult = $LastResult
        message = $Message
        routeFingerprint = $RouteFingerprint
        route = @{
            serviceTier = $Route.ServiceTier
            assetRole = $Route.AssetRole
            backupRequired = $Route.BackupRequired
            labAsset = $Route.LabAsset
            productionFolderTarget = $Route.ProductionFolderTarget
            dnsFilteringRequired = $Route.DNSFilteringRequired
        }
        issues = @($Check.Issues)
        warnings = @($Check.Warnings)
        serviceNames = @($Check.ServiceNames)
        runningServiceNames = @($Check.RunningServiceNames)
        serviceCount = $Check.ServiceCount
        runningServiceCount = $Check.RunningServiceCount
        installFound = $Check.InstallFound
    }
}

$ServiceTier = Get-MMITRuntimeValue -Names @('ServiceTier', 'MMIT Service Tier', 'MMIT_SERVICE_TIER')
$AssetRole = Get-MMITRuntimeValue -Names @('AssetRole', 'MMIT Asset Role', 'MMIT_ASSET_ROLE')
$BackupRequiredRaw = Get-MMITRuntimeValue -Names @('BackupRequired', 'MMIT Backup Required', 'MMIT_BACKUP_REQUIRED') -Default $false
$LabAsset = Get-MMITRuntimeValue -Names @('LabAsset', 'MMIT Lab Asset', 'MMIT_LAB_ASSET') -Default $false
$ProductionFolderTarget = Get-MMITRuntimeValue -Names @('ProductionFolderTarget', 'MMIT Production Folder Target', 'MMIT_PRODUCTION_FOLDER_TARGET')
$DNSFilteringRequired = Get-MMITRuntimeValue -Names @('DNSFilteringRequired', 'DnsFilteringRequired', 'MMIT DNS Filtering Required', 'MMIT_DNS_FILTERING_REQUIRED') -Default $false

$ServiceTierKey = ConvertTo-MMITRouteKey $ServiceTier
$AssetRoleKey = ConvertTo-MMITRouteKey $AssetRole
$BackupRequired = ConvertTo-MMITBoolean $BackupRequiredRaw
$IsWorkstation = @('workstation', 'workstations') -contains $AssetRoleKey
$IsServer = @('server', 'servers') -contains $AssetRoleKey
$TierRequiresWorkstationBackup = $IsWorkstation -and (@('protect', 'protectit', 'govern', 'governit') -contains $ServiceTierKey)
$CoveRequired = $BackupRequired -or $TierRequiresWorkstationBackup

$route = [PSCustomObject]@{
    ServiceTier = ConvertTo-MMITText $ServiceTier
    AssetRole = ConvertTo-MMITText $AssetRole
    BackupRequired = $BackupRequired
    LabAsset = ConvertTo-MMITBoolean $LabAsset
    ProductionFolderTarget = ConvertTo-MMITText $ProductionFolderTarget
    DNSFilteringRequired = ConvertTo-MMITBoolean $DNSFilteringRequired
}
$routeFingerprint = "tier=$($route.ServiceTier)|role=$($route.AssetRole)|backup=$($route.BackupRequired)|dns=$($route.DNSFilteringRequired)|target=$($route.ProductionFolderTarget)"

Write-Host "MMIT route: ServiceTier='$($route.ServiceTier)' AssetRole='$($route.AssetRole)' BackupRequired='$($route.BackupRequired)' CoveRequired='$CoveRequired'"
Write-Host "MMIT route detail: LabAsset='$($route.LabAsset)' ProductionFolderTarget='$($route.ProductionFolderTarget)' DNSFilteringRequired='$($route.DNSFilteringRequired)'"
Write-Host "RouteFingerprint: $routeFingerprint"

if (-not ($IsWorkstation -or $IsServer)) {
    $emptyCheck = [PSCustomObject]@{ Issues = @(); Warnings = @(); ServiceNames = @(); RunningServiceNames = @(); ServiceCount = 0; RunningServiceCount = 0; InstallFound = $false }
    Write-Host "MMIT_COVE_STATUS: SKIPPED_UNKNOWN_ROLE"
    Write-MMITCoveState -Status 'SKIPPED_UNKNOWN_ROLE' -Required $false -RouteFingerprint $routeFingerprint -Route $route -Check $emptyCheck -LastResult 'SKIPPED_UNKNOWN_ROLE' -Message "AssetRole='$AssetRole' is not Workstation or Server."
    exit 0
}

if (-not $CoveRequired) {
    $emptyCheck = [PSCustomObject]@{ Issues = @(); Warnings = @(); ServiceNames = @(); RunningServiceNames = @(); ServiceCount = 0; RunningServiceCount = 0; InstallFound = $false }
    Write-Host "MMIT_COVE_STATUS: SKIPPED_NOT_REQUIRED"
    Write-Host "Cove verification skipped because Cove is not required for this route."
    Write-MMITCoveState -Status 'SKIPPED_NOT_REQUIRED' -Required $false -RouteFingerprint $routeFingerprint -Route $route -Check $emptyCheck -LastResult 'SKIPPED_NOT_REQUIRED'
    exit 0
}

$state = Read-MMITJsonFile -Path $StateFile
$stateStatus = (Get-MMITObjectPropertyText -Object $state -Name 'status').ToUpperInvariant()
$stateFingerprint = Get-MMITObjectPropertyText -Object $state -Name 'routeFingerprint'

if ($stateStatus -eq 'HEALTHY' -and $stateFingerprint -eq $routeFingerprint) {
    $quickCheck = Invoke-MMITCoveCheck -Phase 'QuickCached'
    if ($quickCheck.Issues.Count -eq 0) {
        Write-Host ""
        Write-Host "==== Final Result ===="
        Write-Host "MMIT_COVE_STATUS: ALREADY_HEALTHY"
        Write-Host "Previous healthy state matches this route; light live check passed. Full verification skipped."
        Write-MMITCoveState -Status 'HEALTHY' -Required $true -RouteFingerprint $routeFingerprint -Route $route -Check $quickCheck -LastResult 'ALREADY_HEALTHY' -Message 'Previous healthy state matched this route; light live check passed.'
        exit 0
    }

    Write-Host "Cached healthy state found, but light live check found issues. Running full verification."
}

$fullCheck = Invoke-MMITCoveCheck -Phase 'Full'

Write-Host ""
Write-Host "==== Final Result ===="

if ($fullCheck.Issues.Count -eq 0) {
    Write-Host "MMIT_COVE_STATUS: HEALTHY"
    Write-MMITCoveState -Status 'HEALTHY' -Required $true -RouteFingerprint $routeFingerprint -Route $route -Check $fullCheck -LastResult 'HEALTHY' -Message 'Full verification passed.'
    exit 0
}

Write-Host "MMIT_COVE_STATUS: ISSUE_FOUND"
Write-Host 'Issues:'
foreach ($issue in $fullCheck.Issues) { Write-Host "- $issue" }
Write-MMITCoveState -Status 'ISSUE_FOUND' -Required $true -RouteFingerprint $routeFingerprint -Route $route -Check $fullCheck -LastResult 'ISSUE_FOUND' -Message 'Full verification found issues.'
exit 1
