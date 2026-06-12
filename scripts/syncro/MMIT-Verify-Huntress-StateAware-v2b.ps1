# MMIT-Verify-Huntress v2b
# READ-ONLY: Route-aware, state-aware verification of Huntress agent installation and health.
#
# Behavior:
# - Skips when Huntress is not required.
# - Writes state to C:\ProgramData\MMIT\Onboarding\Huntress-verify.json.
# - If a previous HEALTHY state exists for the same route, performs a light live check only.
# - If the light live check passes, exits 0 as ALREADY_HEALTHY.
# - If no valid healthy state exists, performs full verification.
#
# Run as: System
# Recommended Syncro schedule while in Deploy: if not run in past 4-6 hours

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Continue"

$Component = "Huntress"
$StateDir = "C:\ProgramData\MMIT\Onboarding"
$LogDir = "C:\ProgramData\MMIT\Logs"
$StateFile = Join-Path $StateDir "$Component-verify.json"

New-Item -ItemType Directory -Path $StateDir -Force | Out-Null
New-Item -ItemType Directory -Path $LogDir -Force | Out-Null

Write-Host "==== MMIT Huntress Verification v2b ===="
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

function Test-MMITInstalledProgram {
    param([Parameter(Mandatory = $true)][string[]]$NamePatterns)

    $registryPaths = @(
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    )

    foreach ($path in $registryPaths) {
        $items = Get-ItemProperty -Path $path -ErrorAction SilentlyContinue
        foreach ($item in @($items)) {
            $displayName = Get-MMITObjectPropertyText -Object $item -Name 'DisplayName'
            if ($displayName -eq '') { continue }
            foreach ($pattern in $NamePatterns) {
                if ($displayName -like $pattern) {
                    Write-Host "InstalledProgramFound: $displayName"
                    return $true
                }
            }
        }
    }

    return $false
}

function Get-MMITHuntressServices {
    $services = @()
    foreach ($svcName in @('HuntressAgent', 'HuntressUpdater')) {
        $svc = Get-Service -Name $svcName -ErrorAction SilentlyContinue
        if ($svc) { $services += $svc }
    }

    if ($services.Count -eq 0) {
        $services = @(Get-Service -ErrorAction SilentlyContinue | Where-Object {
            $_.Name -match 'Huntress' -or $_.DisplayName -match 'Huntress'
        })
    }

    return $services
}

function Test-MMITHuntressInstallMarker {
    $paths = @(
        'C:\Program Files\Huntress',
        'C:\Program Files (x86)\Huntress'
    )

    foreach ($path in $paths) {
        if (Test-Path -LiteralPath $path) {
            Write-Host "InstallPathFound: $path"
            return $true
        }
    }

    return (Test-MMITInstalledProgram -NamePatterns @('*Huntress*'))
}

function Invoke-MMITHuntressCheck {
    param([Parameter(Mandatory = $true)][string]$Phase)

    Write-Host ""
    Write-Host "==== Huntress Check: $Phase ===="

    $issues = @()
    $warnings = @()
    $serviceNames = @()
    $runningServiceNames = @()

    $services = @(Get-MMITHuntressServices)
    foreach ($svc in $services) {
        $serviceNames += $svc.Name
        Write-Host ""
        Write-Host "Service Found: $($svc.Name)"
        Write-Host "DisplayName: $($svc.DisplayName)"
        Write-Host "Status: $($svc.Status)"
        if ($svc.PSObject.Properties.Name -contains 'StartType') {
            Write-Host "StartType: $($svc.StartType)"
            if ($svc.StartType -ne 'Automatic') {
                $warnings += "$($svc.Name) is not set to Automatic. Current StartType: $($svc.StartType)."
            }
        }
        if ($svc.Status -eq 'Running') {
            $runningServiceNames += $svc.Name
        }
    }

    if ($services.Count -eq 0) {
        $issues += 'No Huntress services were found.'
    } elseif ($runningServiceNames.Count -eq 0) {
        $issues += 'Huntress services were found, but none are running.'
    }

    $installFound = Test-MMITHuntressInstallMarker
    if (-not $installFound) {
        $issues += 'Huntress installation folder or installed program entry not found.'
    }

    if ($Phase -eq 'Full') {
        Write-Host ""
        Write-Host "==== Recent Huntress Events (Last 24 Hours) ===="
        try {
            $events = Get-WinEvent -FilterHashtable @{
                LogName = 'Application'
                StartTime = (Get-Date).AddHours(-24)
            } -ErrorAction SilentlyContinue | Where-Object {
                $_.ProviderName -match 'Huntress'
            }

            if ($events) {
                $events | Select-Object TimeCreated, Id, LevelDisplayName, ProviderName -First 10 | Format-Table -Auto | Out-Host
                Write-Host 'RecentHuntressEvents: FOUND'
            } else {
                Write-Host 'RecentHuntressEvents: NONE'
                $warnings += 'No Huntress application events found in the last 24 hours.'
            }
        } catch {
            Write-Host 'EventLogQuery: FAILED'
            $warnings += 'Event log query failed.'
        }
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

function Write-MMITHuntressState {
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
$BackupRequired = Get-MMITRuntimeValue -Names @('BackupRequired', 'MMIT Backup Required', 'MMIT_BACKUP_REQUIRED') -Default $false
$LabAsset = Get-MMITRuntimeValue -Names @('LabAsset', 'MMIT Lab Asset', 'MMIT_LAB_ASSET') -Default $false
$ProductionFolderTarget = Get-MMITRuntimeValue -Names @('ProductionFolderTarget', 'MMIT Production Folder Target', 'MMIT_PRODUCTION_FOLDER_TARGET')
$DNSFilteringRequired = Get-MMITRuntimeValue -Names @('DNSFilteringRequired', 'DnsFilteringRequired', 'MMIT DNS Filtering Required', 'MMIT_DNS_FILTERING_REQUIRED') -Default $false

$ServiceTierKey = ConvertTo-MMITRouteKey $ServiceTier
$HuntressRequired = @('protect', 'protectit', 'govern', 'governit') -contains $ServiceTierKey

$route = [PSCustomObject]@{
    ServiceTier = ConvertTo-MMITText $ServiceTier
    AssetRole = ConvertTo-MMITText $AssetRole
    BackupRequired = ConvertTo-MMITBoolean $BackupRequired
    LabAsset = ConvertTo-MMITBoolean $LabAsset
    ProductionFolderTarget = ConvertTo-MMITText $ProductionFolderTarget
    DNSFilteringRequired = ConvertTo-MMITBoolean $DNSFilteringRequired
}
$routeFingerprint = "tier=$($route.ServiceTier)|role=$($route.AssetRole)|backup=$($route.BackupRequired)|dns=$($route.DNSFilteringRequired)|target=$($route.ProductionFolderTarget)"

Write-Host "MMIT route: ServiceTier='$($route.ServiceTier)' AssetRole='$($route.AssetRole)' HuntressRequired='$HuntressRequired'"
Write-Host "MMIT route detail: BackupRequired='$($route.BackupRequired)' LabAsset='$($route.LabAsset)' ProductionFolderTarget='$($route.ProductionFolderTarget)' DNSFilteringRequired='$($route.DNSFilteringRequired)'"
Write-Host "RouteFingerprint: $routeFingerprint"

if (-not $HuntressRequired) {
    $emptyCheck = [PSCustomObject]@{ Issues = @(); Warnings = @(); ServiceNames = @(); RunningServiceNames = @(); ServiceCount = 0; RunningServiceCount = 0; InstallFound = $false }
    Write-Host "MMIT_HUNTRESS_STATUS: SKIPPED_NOT_REQUIRED"
    Write-Host "Huntress verification skipped because Huntress is not required for ServiceTier='$ServiceTier'."
    Write-MMITHuntressState -Status 'SKIPPED_NOT_REQUIRED' -Required $false -RouteFingerprint $routeFingerprint -Route $route -Check $emptyCheck -LastResult 'SKIPPED_NOT_REQUIRED'
    exit 0
}

$state = Read-MMITJsonFile -Path $StateFile
$stateStatus = (Get-MMITObjectPropertyText -Object $state -Name 'status').ToUpperInvariant()
$stateFingerprint = Get-MMITObjectPropertyText -Object $state -Name 'routeFingerprint'

if ($stateStatus -eq 'HEALTHY' -and $stateFingerprint -eq $routeFingerprint) {
    $quickCheck = Invoke-MMITHuntressCheck -Phase 'QuickCached'
    if ($quickCheck.Issues.Count -eq 0) {
        Write-Host ""
        Write-Host "==== Final Result ===="
        Write-Host "MMIT_HUNTRESS_STATUS: ALREADY_HEALTHY"
        Write-Host "Previous healthy state matches this route; light live check passed. Full verification skipped."
        Write-MMITHuntressState -Status 'HEALTHY' -Required $true -RouteFingerprint $routeFingerprint -Route $route -Check $quickCheck -LastResult 'ALREADY_HEALTHY' -Message 'Previous healthy state matched this route; light live check passed.'
        exit 0
    }

    Write-Host "Cached healthy state found, but light live check found issues. Running full verification."
}

$fullCheck = Invoke-MMITHuntressCheck -Phase 'Full'

Write-Host ""
Write-Host "==== Final Result ===="

if ($fullCheck.Warnings.Count -gt 0) {
    Write-Host 'Warnings:'
    foreach ($warning in $fullCheck.Warnings) { Write-Host "- $warning" }
}

if ($fullCheck.Issues.Count -eq 0) {
    Write-Host "MMIT_HUNTRESS_STATUS: HEALTHY"
    Write-MMITHuntressState -Status 'HEALTHY' -Required $true -RouteFingerprint $routeFingerprint -Route $route -Check $fullCheck -LastResult 'HEALTHY' -Message 'Full verification passed.'
    exit 0
}

Write-Host "MMIT_HUNTRESS_STATUS: ISSUE_FOUND"
Write-Host 'Issues:'
foreach ($issue in $fullCheck.Issues) { Write-Host "- $issue" }
Write-MMITHuntressState -Status 'ISSUE_FOUND' -Required $true -RouteFingerprint $routeFingerprint -Route $route -Check $fullCheck -LastResult 'ISSUE_FOUND' -Message 'Full verification found issues.'
exit 1
