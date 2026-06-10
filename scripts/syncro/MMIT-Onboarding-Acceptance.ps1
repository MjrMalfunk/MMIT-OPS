# MMIT-Onboarding-Acceptance.ps1
# Copy-ready Syncro cloud script for MMIT route-aware onboarding acceptance.
#
# This script intentionally does NOT move assets. It only evaluates local checks,
# updates onboarding custom fields, and creates the ready/move ticket once when
# the route-aware acceptance decision is READY.

Set-StrictMode -Version 2.0

Write-Output "MMIT-Onboarding-Acceptance version: route-aware-server-scoutdns-skip-ticketid-numeric-20260609"

$script:MMITRequiredCustomFields = @(
    'MMIT Service Tier',
    'MMIT Asset Role',
    'MMIT Backup Required',
    'MMIT Lab Asset',
    'MMIT Production Folder Target',
    'MMIT DNS Filtering Required',
    'MMIT Onboarding Status',
    'MMIT Ready To Move',
    'MMIT Onboarding Result',
    'MMIT Auto Move Result'
)

function ConvertTo-MMITText {
    param([AllowNull()][object]$Value)

    if ($null -eq $Value) {
        return ''
    }

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

    if ($null -eq $Value) {
        return $false
    }

    if ($Value -is [bool]) {
        return [bool]$Value
    }

    if ($Value -is [int] -or $Value -is [long]) {
        return ([int]$Value) -ne 0
    }

    $text = (ConvertTo-MMITText $Value).ToLowerInvariant()
    return @('1', 'true', 'yes', 'y', 'checked', 'on', 'enabled', 'required') -contains $text
}

function Get-MMITCustomFieldValue {
    param(
        [Parameter(Mandatory = $true)][hashtable]$CustomFields,
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()][object]$Default = $null
    )

    if ($CustomFields.ContainsKey($Name)) {
        return $CustomFields[$Name]
    }

    $normalizedName = ConvertTo-MMITRouteKey $Name
    foreach ($key in $CustomFields.Keys) {
        if ((ConvertTo-MMITRouteKey $key) -eq $normalizedName) {
            return $CustomFields[$key]
        }
    }

    return $Default
}

function Test-MMITCustomFieldPresent {
    param(
        [Parameter(Mandatory = $true)][hashtable]$CustomFields,
        [Parameter(Mandatory = $true)][string]$Name
    )

    if ($CustomFields.ContainsKey($Name)) {
        return $true
    }

    $normalizedName = ConvertTo-MMITRouteKey $Name
    foreach ($key in $CustomFields.Keys) {
        if ((ConvertTo-MMITRouteKey $key) -eq $normalizedName) {
            return $true
        }
    }

    return $false
}

function New-MMITRouteRequirement {
    param(
        [Parameter(Mandatory = $true)][bool]$Required,
        [Parameter(Mandatory = $true)][string]$Reason
    )

    return [ordered]@{
        Required = $Required
        Reason = $Reason
    }
}

function Get-MMITOnboardingRoute {
    param([Parameter(Mandatory = $true)][hashtable]$CustomFields)

    $serviceTierRaw = Get-MMITCustomFieldValue -CustomFields $CustomFields -Name 'MMIT Service Tier' -Default ''
    $assetRoleRaw = Get-MMITCustomFieldValue -CustomFields $CustomFields -Name 'MMIT Asset Role' -Default ''
    $backupRaw = Get-MMITCustomFieldValue -CustomFields $CustomFields -Name 'MMIT Backup Required' -Default $false
    $labRaw = Get-MMITCustomFieldValue -CustomFields $CustomFields -Name 'MMIT Lab Asset' -Default $false
    $targetRaw = Get-MMITCustomFieldValue -CustomFields $CustomFields -Name 'MMIT Production Folder Target' -Default ''
    $dnsFieldPresent = Test-MMITCustomFieldPresent -CustomFields $CustomFields -Name 'MMIT DNS Filtering Required'
    $dnsRaw = Get-MMITCustomFieldValue -CustomFields $CustomFields -Name 'MMIT DNS Filtering Required' -Default $false

    $serviceTierKey = ConvertTo-MMITRouteKey $serviceTierRaw
    $assetRoleKey = ConvertTo-MMITRouteKey $assetRoleRaw
    $backupRequired = ConvertTo-MMITBoolean $backupRaw
    $dnsRequired = $dnsFieldPresent -and (ConvertTo-MMITBoolean $dnsRaw)
    $isWorkstation = @('workstation', 'workstations') -contains $assetRoleKey
    $isServer = @('server', 'servers') -contains $assetRoleKey
    $isManage = @('manage', 'manageit') -contains $serviceTierKey
    $isProtect = @('protect', 'protectit') -contains $serviceTierKey
    $isGovern = @('govern', 'governit') -contains $serviceTierKey
    $serviceTierValid = ($isManage -or $isProtect -or $isGovern)
    $assetRoleValid = ($isWorkstation -or $isServer)
    $routeValidationFailures = New-Object System.Collections.Generic.List[string]
    $routeValidationLines = New-Object System.Collections.Generic.List[string]

    $serviceTierText = ConvertTo-MMITText $serviceTierRaw
    if ($serviceTierText -eq '') {
        $routeValidationFailures.Add('ServiceTier')
        $routeValidationLines.Add('Route validation: FAIL - missing ServiceTier')
    } elseif (-not $serviceTierValid) {
        $routeValidationFailures.Add('ServiceTier')
        $routeValidationLines.Add(("Route validation: FAIL - unrecognized ServiceTier '{0}'" -f $serviceTierText))
    }

    $assetRoleText = ConvertTo-MMITText $assetRoleRaw
    if ($assetRoleText -eq '') {
        $routeValidationFailures.Add('AssetRole')
        $routeValidationLines.Add('Route validation: FAIL - missing AssetRole')
    } elseif (-not $assetRoleValid) {
        $routeValidationFailures.Add('AssetRole')
        $routeValidationLines.Add(("Route validation: FAIL - unrecognized AssetRole '{0}'" -f $assetRoleText))
    }

    if ($routeValidationFailures.Count -eq 0) {
        $routeValidationLines.Add('Route validation: PASS')
    }

    $huntressRequired = ($isProtect -or $isGovern)
    $scoutDnsRequired = (-not $isServer) -and ($dnsRequired -or ($isProtect -or $isGovern))

    if ($isServer) {
        $coveRequired = $backupRequired
    } elseif ($isWorkstation -and ($isProtect -or $isGovern)) {
        $coveRequired = $true
    } else {
        $coveRequired = $backupRequired
    }

    $serviceTierReasonText = $serviceTierText
    if ($serviceTierReasonText -eq '') {
        $serviceTierReasonText = 'unspecified service tier'
    }

    $huntressReason = if ($huntressRequired) { 'required for Protect/Govern IT' } else { "not required for $serviceTierReasonText" }
    $scoutDnsReason = if ($isServer) {
        'server ScoutDNS Device Agent not required; use site DNS/firewall/WAN forwarding/Relay/server policy'
    } elseif ($scoutDnsRequired) {
        if ($dnsRequired) { 'DNS filtering selected' } else { 'required for Protect/Govern IT' }
    } elseif ($dnsFieldPresent) {
        'DNS filtering not selected'
    } else {
        'DNS filtering field not present'
    }
    $coveReason = if ($coveRequired) {
        if ($isWorkstation -and ($isProtect -or $isGovern)) { 'workstation backup required for Protect/Govern IT' } else { 'backup selected' }
    } else {
        'backup not selected'
    }

    return [ordered]@{
        ServiceTier = $serviceTierText
        ServiceTierKey = $serviceTierKey
        ServiceTierValid = $serviceTierValid
        AssetRole = $assetRoleText
        AssetRoleKey = $assetRoleKey
        AssetRoleValid = $assetRoleValid
        RouteValidationFailures = @($routeValidationFailures)
        RouteValidationLines = @($routeValidationLines)
        BackupRequired = $backupRequired
        LabAsset = ConvertTo-MMITBoolean $labRaw
        ProductionFolderTarget = ConvertTo-MMITText $targetRaw
        DnsFilteringFieldPresent = $dnsFieldPresent
        DnsFilteringRequired = $dnsRequired
        Requirements = [ordered]@{
            Defender = New-MMITRouteRequirement -Required $true -Reason 'required where applicable'
            Huntress = New-MMITRouteRequirement -Required $huntressRequired -Reason $huntressReason
            ScoutDNS = New-MMITRouteRequirement -Required $scoutDnsRequired -Reason $scoutDnsReason
            CoveAgent = New-MMITRouteRequirement -Required $coveRequired -Reason $coveReason
            CoveBackupComplete = New-MMITRouteRequirement -Required $coveRequired -Reason $coveReason
        }
    }
}

function ConvertTo-MMITCheckState {
    param([AllowNull()][object]$Value)

    $state = (ConvertTo-MMITText $Value).ToUpperInvariant()
    if ($state -eq '') {
        return 'FAIL'
    }

    if (@('PASS', 'PASSED', 'OK', 'SUCCESS') -contains $state) {
        return 'PASS'
    }

    if (@('SKIP', 'SKIPPED', 'NOTREQUIRED', 'NOT_REQUIRED') -contains $state) {
        return 'SKIPPED'
    }

    return 'FAIL'
}

function Get-MMITOnboardingDecision {
    param(
        [Parameter(Mandatory = $true)][hashtable]$CustomFields,
        [Parameter(Mandatory = $true)][hashtable]$CheckResults
    )

    $route = Get-MMITOnboardingRoute -CustomFields $CustomFields
    $failures = New-Object System.Collections.Generic.List[string]
    $summaryLines = New-Object System.Collections.Generic.List[string]

    foreach ($routeFailure in @($route.RouteValidationFailures)) {
        $failures.Add($routeFailure)
    }

    foreach ($routeValidationLine in @($route.RouteValidationLines)) {
        $summaryLines.Add($routeValidationLine)
    }

    foreach ($checkName in @('Defender', 'ScoutDNS', 'Huntress', 'CoveAgent', 'CoveBackupComplete')) {
        $requirement = $route.Requirements[$checkName]
        $rawState = if ($CheckResults.ContainsKey($checkName)) { $CheckResults[$checkName] } else { 'FAIL' }
        $state = ConvertTo-MMITCheckState $rawState

        if (-not [bool]$requirement.Required) {
            $summaryLines.Add(('{0}: SKIPPED - {1}' -f $checkName, $requirement.Reason))
            continue
        }

        if ($state -eq 'PASS') {
            $summaryLines.Add(('{0}: PASS' -f $checkName))
            continue
        }

        $summaryLines.Add(('{0}: FAIL - {1}' -f $checkName, $requirement.Reason))
        $failures.Add($checkName)
    }

    $isReady = $failures.Count -eq 0
    $status = if ($isReady) { 'READY' } else { 'NOT_READY' }
    $readyToMove = if ($isReady) { 'Yes' } else { 'No' }
    $failureText = if ($failures.Count -gt 0) { ($failures -join ', ') } else { 'None' }

    $header = @(
        ('Route: Service Tier={0}; Asset Role={1}; Backup Required={2}; DNS Filtering Required={3}; Lab Asset={4}; Production Folder Target={5}' -f $route.ServiceTier, $route.AssetRole, $route.BackupRequired, $route.DnsFilteringRequired, $route.LabAsset, $route.ProductionFolderTarget),
        ('MMIT_ONBOARDING_STATUS: {0}' -f $status),
        ('MMIT_READY_TO_MOVE: {0}' -f $readyToMove),
        ('Required check failures: {0}' -f $failureText)
    )

    return [ordered]@{
        Route = $route
        Status = $status
        ReadyToMove = $readyToMove
        Failures = @($failures)
        Summary = (($header + @($summaryLines)) -join [Environment]::NewLine)
    }
}

function Get-MMITFirstValue {
    param([string[]]$Names)

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

    return ''
}

function Get-MMITComputerName {
    $computerName = ConvertTo-MMITText $env:COMPUTERNAME
    if ($computerName -eq '') {
        $computerName = ConvertTo-MMITText ([Environment]::MachineName)
    }
    if ($computerName -eq '') {
        return 'unknown-computer'
    }
    return $computerName
}

function Import-MMITSyncroModule {
    $modulePath = ConvertTo-MMITText $env:SyncroModule
    if ($modulePath -eq '') {
        throw 'Syncro module path is missing. Syncro must provide the SyncroModule environment variable.'
    }
    if (-not (Test-Path -LiteralPath $modulePath)) {
        throw ('Syncro module path does not exist: {0}' -f $modulePath)
    }

    Import-Module -Name $modulePath -Force -ErrorAction Stop

    foreach ($commandName in @('Set-Asset-Field', 'Create-Syncro-Ticket', 'Log-Activity')) {
        if ($null -eq (Get-Command -Name $commandName -ErrorAction SilentlyContinue)) {
            throw ('Imported Syncro module is missing required helper: {0}' -f $commandName)
        }
    }
}

function Get-MMITSyncroSubdomain {
    $subdomain = Get-MMITFirstValue -Names @('RepairTechSyncroSubDomain', 'SYNCRO_SUBDOMAIN', 'SyncroSubdomain')
    if ($subdomain -eq '') {
        throw 'Syncro subdomain is missing. Syncro must provide the RepairTechSyncroSubDomain environment variable.'
    }
    return $subdomain
}

function Get-MMITRuntimeValue {
    param(
        [Parameter(Mandatory = $true)][string[]]$Names,
        [AllowNull()][object]$Default = ''
    )

    $value = Get-MMITFirstValue -Names $Names
    if ((ConvertTo-MMITText $value) -ne '') {
        return $value
    }

    return $Default
}

function Get-MMITParameterOrFallbackValue {
    param(
        [AllowNull()][object]$ConfiguredValue,
        [Parameter(Mandatory = $true)][string[]]$FallbackNames,
        [AllowNull()][object]$Default = ''
    )

    if ($null -ne $ConfiguredValue -and (ConvertTo-MMITText $ConfiguredValue) -ne '') {
        return $ConfiguredValue
    }

    $value = Get-MMITFirstValue -Names $FallbackNames
    if ($value -ne '') {
        return $value
    }

    return $Default
}

function Get-MMITAssetCustomFieldsFromInputs {
    param(
        [AllowNull()][object]$ServiceTier,
        [AllowNull()][object]$AssetRole,
        [AllowNull()][object]$BackupRequired,
        [AllowNull()][object]$LabAsset,
        [AllowNull()][object]$ProductionFolderTarget,
        [AllowNull()][object]$DnsFilteringRequired
    )

    return @{
        'MMIT Service Tier' = Get-MMITParameterOrFallbackValue -ConfiguredValue $ServiceTier -FallbackNames @('MMIT Service Tier', 'MMIT_SERVICE_TIER', 'ServiceTier') -Default ''
        'MMIT Asset Role' = Get-MMITParameterOrFallbackValue -ConfiguredValue $AssetRole -FallbackNames @('MMIT Asset Role', 'MMIT_ASSET_ROLE', 'AssetRole') -Default ''
        'MMIT Backup Required' = Get-MMITParameterOrFallbackValue -ConfiguredValue $BackupRequired -FallbackNames @('MMIT Backup Required', 'MMIT_BACKUP_REQUIRED', 'BackupRequired') -Default $false
        'MMIT Lab Asset' = Get-MMITParameterOrFallbackValue -ConfiguredValue $LabAsset -FallbackNames @('MMIT Lab Asset', 'MMIT_LAB_ASSET', 'LabAsset') -Default $false
        'MMIT Production Folder Target' = Get-MMITParameterOrFallbackValue -ConfiguredValue $ProductionFolderTarget -FallbackNames @('MMIT Production Folder Target', 'MMIT_PRODUCTION_FOLDER_TARGET', 'ProductionFolderTarget') -Default ''
        'MMIT DNS Filtering Required' = Get-MMITParameterOrFallbackValue -ConfiguredValue $DnsFilteringRequired -FallbackNames @('MMIT DNS Filtering Required', 'MMIT_DNS_FILTERING_REQUIRED', 'DNSFilteringRequired', 'DnsFilteringRequired') -Default $false
    }
}

function Update-MMITSyncroAssetCustomFields {
    param(
        [Parameter(Mandatory = $true)][hashtable]$CustomFields,
        [Parameter(Mandatory = $true)][string]$Subdomain
    )

    foreach ($fieldName in $CustomFields.Keys) {
        if ($script:MMITWhatIfSyncro) {
            Write-Output ('WHATIF Set-Asset-Field -Name ''{0}'' -Value ''{1}'' -Subdomain ''{2}''' -f $fieldName, $CustomFields[$fieldName], $Subdomain)
            continue
        }

        Set-Asset-Field -Name $fieldName -Value $CustomFields[$fieldName] -Subdomain $Subdomain
    }
}

function Test-MMITWindowsServiceRunning {
    param([Parameter(Mandatory = $true)][string[]]$Names)

    foreach ($name in $Names) {
        $service = Get-Service -Name $name -ErrorAction SilentlyContinue
        if ($null -ne $service -and $service.Status -eq 'Running') {
            return $true
        }
    }

    return $false
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
            $displayName = ''
            if ($null -ne $item -and $null -ne $item.PSObject.Properties['DisplayName']) {
                $displayName = ConvertTo-MMITText $item.DisplayName
            }
            if ($displayName -eq '') {
                continue
            }

            foreach ($pattern in $NamePatterns) {
                if ($displayName -like $pattern) {
                    return $true
                }
            }
        }
    }

    return $false
}

function Test-MMITDefender {
    try {
        $status = Get-MpComputerStatus -ErrorAction Stop
        return (($status.AMServiceEnabled -eq $true) -and ($status.AntivirusEnabled -eq $true) -and ($status.RealTimeProtectionEnabled -eq $true))
    } catch {
        return (Test-MMITWindowsServiceRunning -Names @('WinDefend'))
    }
}

function Test-MMITHuntress {
    return (Test-MMITWindowsServiceRunning -Names @('HuntressAgent', 'Huntress Agent')) -or (Test-MMITInstalledProgram -NamePatterns @('*Huntress*'))
}

function Test-MMITScoutDNS {
    return (Test-MMITWindowsServiceRunning -Names @('ScoutDNSAgent', 'ScoutDNS Agent', 'ScoutDNS')) -or (Test-MMITInstalledProgram -NamePatterns @('*ScoutDNS*', '*Scout DNS*'))
}

function Test-MMITCoveAgent {
    return (Test-MMITWindowsServiceRunning -Names @('Backup Service Controller', 'BackupFP', 'CoveBackup')) -or (Test-MMITInstalledProgram -NamePatterns @('*Cove*', '*N-able Backup*', '*N-able Cove*'))
}

function Test-MMITCoveBackupComplete {
    $statusPaths = @(
        'C:\ProgramData\MXB\Backup Manager\StatusReport.xml',
        'C:\ProgramData\Managed Online Backup\Backup Manager\StatusReport.xml'
    )

    foreach ($path in $statusPaths) {
        if (Test-Path -LiteralPath $path) {
            try {
                $content = Get-Content -LiteralPath $path -Raw -ErrorAction Stop
                if ($content -match '(?i)(completed|success|succeeded)' -and $content -notmatch '(?i)(failed|error)') {
                    return $true
                }
            } catch {
                Write-Output ('Unable to read Cove status file {0}: {1}' -f $path, $_.Exception.Message)
            }
        }
    }

    return $false
}

function Get-MMITLocalCheckResults {
    $results = @{}
    $results.Defender = if (Test-MMITDefender) { 'PASS' } else { 'FAIL' }
    $results.ScoutDNS = if (Test-MMITScoutDNS) { 'PASS' } else { 'FAIL' }
    $results.Huntress = if (Test-MMITHuntress) { 'PASS' } else { 'FAIL' }
    $results.CoveAgent = if (Test-MMITCoveAgent) { 'PASS' } else { 'FAIL' }
    $results.CoveBackupComplete = if (Test-MMITCoveBackupComplete) { 'PASS' } else { 'FAIL' }
    return $results
}

function Get-MMITMarkerPath {
    param(
        [string]$ConfiguredMarkerPath,
        [string]$ComputerName
    )

    $path = ConvertTo-MMITText $ConfiguredMarkerPath
    if ($path -ne '') {
        return $path
    }

    $programData = ConvertTo-MMITText $env:ProgramData
    if ($programData -eq '') {
        $programData = 'C:\ProgramData'
    }

    $safeComputerName = if ((ConvertTo-MMITText $ComputerName) -ne '') { ($ComputerName -replace '[^A-Za-z0-9_.-]+', '_') } else { 'unknown-computer' }
    return (Join-Path $programData ('MMIT\OnboardingAcceptance\ready-ticket-{0}.marker' -f $safeComputerName))
}

function Test-MMITReadyTicketMarker {
    param([Parameter(Mandatory = $true)][string]$Path)

    return (Test-Path -LiteralPath $Path)
}

function Set-MMITReadyTicketMarker {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [AllowEmptyString()][string]$TicketId
    )

    $directory = Split-Path -Path $Path -Parent
    if ((ConvertTo-MMITText $directory) -ne '' -and -not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    @(
        ('CreatedUtc={0}' -f ([DateTime]::UtcNow.ToString('o'))),
        ('TicketId={0}' -f $TicketId)
    ) | Set-Content -LiteralPath $Path -Encoding UTF8
}

function Test-MMITNumericTicketId {
    param([AllowNull()][object]$Value)

    $text = ConvertTo-MMITText $Value
    return ($text -match '^\d+$')
}

function Get-MMITTicketIdFromResponse {
    param([AllowNull()][object]$Response)

    if ($null -eq $Response) {
        return ''
    }

    if (Test-MMITNumericTicketId $Response) {
        return (ConvertTo-MMITText $Response)
    }

    $ticketIdPropertyNames = @('id', 'number', 'ticket_id', 'ticket_number')
    $containerPropertyNames = @('ticket', 'data', 'response', 'result')

    if ($Response -is [System.Collections.IDictionary]) {
        foreach ($propertyName in $ticketIdPropertyNames) {
            if ($Response.Contains($propertyName)) {
                $ticketId = Get-MMITTicketIdFromResponse -Response $Response[$propertyName]
                if (Test-MMITNumericTicketId $ticketId) {
                    return $ticketId
                }
            }
        }

        foreach ($containerName in $containerPropertyNames) {
            if ($Response.Contains($containerName)) {
                $ticketId = Get-MMITTicketIdFromResponse -Response $Response[$containerName]
                if (Test-MMITNumericTicketId $ticketId) {
                    return $ticketId
                }
            }
        }

        return ''
    }

    if ($Response -is [System.Collections.IEnumerable] -and -not ($Response -is [string])) {
        foreach ($item in $Response) {
            $ticketId = Get-MMITTicketIdFromResponse -Response $item
            if (Test-MMITNumericTicketId $ticketId) {
                return $ticketId
            }
        }

        return ''
    }

    foreach ($propertyName in $ticketIdPropertyNames) {
        if ($null -ne $Response.PSObject.Properties[$propertyName]) {
            $ticketId = Get-MMITTicketIdFromResponse -Response $Response.PSObject.Properties[$propertyName].Value
            if (Test-MMITNumericTicketId $ticketId) {
                return $ticketId
            }
        }
    }

    foreach ($containerName in $containerPropertyNames) {
        if ($null -ne $Response.PSObject.Properties[$containerName]) {
            $ticketId = Get-MMITTicketIdFromResponse -Response $Response.PSObject.Properties[$containerName].Value
            if (Test-MMITNumericTicketId $ticketId) {
                return $ticketId
            }
        }
    }

    return ''
}

function Set-MMITReadyMoveTicketAssetField {
    param(
        [AllowNull()][object]$TicketId,
        [Parameter(Mandatory = $true)][string]$Subdomain
    )

    if (-not (Test-MMITNumericTicketId $TicketId)) {
        Write-Output ('Ready/move ticket ID was blank or non-numeric; MMIT Ready Move Ticket ID custom field was not updated. Extracted value: {0}' -f (ConvertTo-MMITText $TicketId))
        return $false
    }

    $ticketIdText = ConvertTo-MMITText $TicketId
    Update-MMITSyncroAssetCustomFields -CustomFields @{ 'MMIT Ready Move Ticket ID' = $ticketIdText } -Subdomain $Subdomain | Out-Null
    return $true
}

function Add-MMITReadyMoveTicketComment {
    param(
        [Parameter(Mandatory = $true)][string]$TicketIdOrNumber,
        [Parameter(Mandatory = $true)][string]$Subject,
        [Parameter(Mandatory = $true)][string]$Body,
        [Parameter(Mandatory = $true)][string]$Subdomain
    )

    if ($null -eq (Get-Command -Name 'Create-Syncro-Ticket-Comment' -ErrorAction SilentlyContinue)) {
        return
    }

    Create-Syncro-Ticket-Comment `
        -Body $Body `
        -DoNotEmail $true `
        -Hidden $false `
        -Subdomain $Subdomain `
        -Subject $Subject `
        -TicketIdOrNumber $TicketIdOrNumber | Out-Null
}

function New-MMITReadyMoveTicket {
    param(
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Decision,
        [Parameter(Mandatory = $true)][string]$Subdomain,
        [Parameter(Mandatory = $true)][string]$ComputerName
    )

    $subject = $script:MMITReadyTicketSubject
    $bodyText = @(
        'MMIT Auto Move Ready ticket type: MMIT_AUTO_MOVE_READY.',
        '',
        ('Asset name: {0}' -f $ComputerName),
        '',
        'Do not move assets from this acceptance script. Trusted OPS auto-remediation must verify READY, Ready To Move, Deploy source, Production target, and this open ticket before moving.',
        '',
        $Decision.Summary
    ) -join [Environment]::NewLine

    if ($script:MMITWhatIfSyncro) {
        Write-Output ('WHATIF Create-Syncro-Ticket -IssueType ''Onboarding'' -Status ''New'' -Subdomain ''{0}'' -Subject ''{1}''' -f $Subdomain, $subject)
        return 'WHATIF'
    }

    $response = Create-Syncro-Ticket `
        -IssueType 'Onboarding' `
        -Status 'New' `
        -Subdomain $Subdomain `
        -Subject $subject

    $ticketId = Get-MMITTicketIdFromResponse -Response $response
    if (Test-MMITNumericTicketId $ticketId) {
        Add-MMITReadyMoveTicketComment -TicketIdOrNumber $ticketId -Subject $subject -Body $bodyText -Subdomain $Subdomain
    } else {
        Write-Output 'Ready/move ticket was created, but no numeric ticket ID was found in the Syncro response; logging activity instead of writing a ticket ID custom field.'
        Log-Activity -EventName 'MMIT Onboarding Acceptance Ready' -Message $bodyText -Subdomain $Subdomain
    }

    return $ticketId
}

function Write-MMITOnboardingAcceptanceResult {
    param(
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Decision,
        [Parameter(Mandatory = $true)][string]$Subdomain,
        [Parameter(Mandatory = $true)][string]$ComputerName,
        [string]$MarkerPath
    )

    Write-Output $Decision.Summary

    $autoMoveResult = if ($Decision.Status -eq 'READY') {
        ('Ready for production move at {0}' -f ([DateTime]::UtcNow.ToString('o')))
    } else {
        ('Not ready for production move at {0}' -f ([DateTime]::UtcNow.ToString('o')))
    }
    $fieldUpdates = @{
        'MMIT Onboarding Status' = $Decision.Status
        'MMIT Ready To Move' = $Decision.ReadyToMove
        'MMIT Onboarding Result' = $Decision.Summary
        'MMIT Auto Move Result' = $autoMoveResult
    }

    $fieldsUpdated = $false
    Update-MMITSyncroAssetCustomFields -CustomFields $fieldUpdates -Subdomain $Subdomain | Out-Null
    $fieldsUpdated = $true
    Write-Output ('Syncro fields updated: {0}' -f $fieldsUpdated)

    if ($script:MMITWhatIfSyncro) {
        Write-Output ('WHATIF Log-Activity -EventName ''MMIT Onboarding Acceptance'' -Subdomain ''{0}''' -f $Subdomain)
    } else {
        Log-Activity -EventName 'MMIT Onboarding Acceptance' -Message $Decision.Summary -Subdomain $Subdomain
    }

    if ($Decision.Status -ne 'READY') {
        Write-Output 'Asset is not READY; ready/move ticket was not created.'
        return
    }

    if (Test-MMITReadyTicketMarker -Path $MarkerPath) {
        Write-Output ('Ready/move ticket skipped: marker exists at {0}.' -f $MarkerPath)
        return
    }

    $ticketId = New-MMITReadyMoveTicket -Decision $Decision -Subdomain $Subdomain -ComputerName $ComputerName
    if ($script:MMITWhatIfSyncro) {
        Write-Output ('WHATIF ready/move ticket would be marked once. Ticket ID: {0}. Marker: {1}' -f $ticketId, $MarkerPath)
        return
    }

    Set-MMITReadyMoveTicketAssetField -TicketId $ticketId -Subdomain $Subdomain | Out-Null

    Set-MMITReadyTicketMarker -Path $MarkerPath -TicketId $ticketId
    Write-Output ('Ready/move ticket created once. Ticket ID: {0}. Marker: {1}' -f $ticketId, $MarkerPath)
}

function Invoke-MMITOnboardingAcceptance {
    param(
        [AllowNull()][object]$ServiceTier,
        [AllowNull()][object]$AssetRole,
        [AllowNull()][object]$BackupRequired,
        [AllowNull()][object]$LabAsset,
        [AllowNull()][object]$ProductionFolderTarget,
        [AllowNull()][object]$DnsFilteringRequired,
        [string]$MarkerPath,
        [string]$ReadyTicketSubject,
        [switch]$WhatIfSyncro
    )

    $script:MMITWhatIfSyncro = [bool]$WhatIfSyncro
    $computerName = Get-MMITComputerName
    $script:MMITReadyTicketSubject = if ((ConvertTo-MMITText $ReadyTicketSubject) -ne '') { $ReadyTicketSubject } else { ('MMIT Auto Move Ready - {0}' -f $computerName) }

    Import-MMITSyncroModule
    $subdomain = Get-MMITSyncroSubdomain

    $customFields = Get-MMITAssetCustomFieldsFromInputs `
        -ServiceTier $ServiceTier `
        -AssetRole $AssetRole `
        -BackupRequired $BackupRequired `
        -LabAsset $LabAsset `
        -ProductionFolderTarget $ProductionFolderTarget `
        -DnsFilteringRequired $DnsFilteringRequired

    $localChecks = Get-MMITLocalCheckResults
    $decision = Get-MMITOnboardingDecision -CustomFields $customFields -CheckResults $localChecks
    $resolvedMarkerPath = Get-MMITMarkerPath -ConfiguredMarkerPath $MarkerPath -ComputerName $computerName

    Write-MMITOnboardingAcceptanceResult -Decision $decision -Subdomain $subdomain -ComputerName $computerName -MarkerPath $resolvedMarkerPath
}

if ($MyInvocation.InvocationName -ne '.') {
    $runtimeServiceTier = Get-MMITRuntimeValue -Names @('ServiceTier', 'MMIT Service Tier', 'MMIT_SERVICE_TIER')
    $runtimeAssetRole = Get-MMITRuntimeValue -Names @('AssetRole', 'MMIT Asset Role', 'MMIT_ASSET_ROLE')
    $runtimeBackupRequired = Get-MMITRuntimeValue -Names @('BackupRequired', 'MMIT Backup Required', 'MMIT_BACKUP_REQUIRED') -Default $false
    $runtimeLabAsset = Get-MMITRuntimeValue -Names @('LabAsset', 'MMIT Lab Asset', 'MMIT_LAB_ASSET') -Default $false
    $runtimeProductionFolderTarget = Get-MMITRuntimeValue -Names @('ProductionFolderTarget', 'MMIT Production Folder Target', 'MMIT_PRODUCTION_FOLDER_TARGET')
    $runtimeDnsFilteringRequired = Get-MMITRuntimeValue -Names @('DNSFilteringRequired', 'DnsFilteringRequired', 'MMIT DNS Filtering Required', 'MMIT_DNS_FILTERING_REQUIRED') -Default $false
    $runtimeReadyTicketSubject = Get-MMITRuntimeValue -Names @('ReadyTicketSubject', 'MMIT_READY_TICKET_SUBJECT')
    $runtimeMarkerPath = Get-MMITRuntimeValue -Names @('MarkerPath', 'MMIT_ONBOARDING_READY_MARKER_PATH')
    $runtimeWhatIfSyncro = ConvertTo-MMITBoolean (Get-MMITRuntimeValue -Names @('WhatIfSyncro', 'MMIT_WHAT_IF_SYNCRO') -Default $false)

    Invoke-MMITOnboardingAcceptance `
        -ServiceTier $runtimeServiceTier `
        -AssetRole $runtimeAssetRole `
        -BackupRequired $runtimeBackupRequired `
        -LabAsset $runtimeLabAsset `
        -ProductionFolderTarget $runtimeProductionFolderTarget `
        -DnsFilteringRequired $runtimeDnsFilteringRequired `
        -MarkerPath $runtimeMarkerPath `
        -ReadyTicketSubject $runtimeReadyTicketSubject `
        -WhatIfSyncro:$runtimeWhatIfSyncro
}
