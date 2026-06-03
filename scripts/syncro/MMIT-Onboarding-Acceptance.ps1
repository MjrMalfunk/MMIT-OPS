# MMIT-Onboarding-Acceptance.ps1
# Copy-ready Syncro cloud script for MMIT route-aware onboarding acceptance.
#
# This script intentionally does NOT move assets. It only evaluates local checks,
# updates onboarding custom fields, and creates the ready/move ticket once when
# the route-aware acceptance decision is READY.

param(
    [string]$SyncroApiBaseUrl = $env:SYNCRO_API_BASE_URL,
    [string]$SyncroApiKey = $env:SYNCRO_API_KEY,
    [string]$AssetId = $env:SYNCRO_ASSET_ID,
    [string]$CustomerId = $env:SYNCRO_CUSTOMER_ID,
    [string]$ReadyTicketSubject = 'MMIT onboarding acceptance ready to move',
    [string]$MarkerPath = $env:MMIT_ONBOARDING_READY_MARKER_PATH,
    [switch]$WhatIfSyncro
)

Set-StrictMode -Version 2.0

$script:MMITRequiredCustomFields = @(
    'MMIT Service Tier',
    'MMIT Asset Role',
    'MMIT Backup Required',
    'MMIT Lab Asset',
    'MMIT Production Folder Target',
    'MMIT DNS Filtering Required',
    'MMIT Onboarding Status',
    'MMIT Ready To Move',
    'MMIT Onboarding Result'
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

    $huntressRequired = ($isProtect -or $isGovern)
    $scoutDnsRequired = $dnsRequired -or ($isProtect -or $isGovern)

    if ($isServer) {
        $coveRequired = $backupRequired
    } elseif ($isWorkstation -and ($isProtect -or $isGovern)) {
        $coveRequired = $true
    } else {
        $coveRequired = $backupRequired
    }

    $serviceTierText = ConvertTo-MMITText $serviceTierRaw
    if ($serviceTierText -eq '') {
        $serviceTierText = 'unspecified service tier'
    }

    $huntressReason = if ($huntressRequired) { 'required for Protect/Govern IT' } else { "not required for $serviceTierText" }
    $scoutDnsReason = if ($scoutDnsRequired) {
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
        ServiceTier = ConvertTo-MMITText $serviceTierRaw
        ServiceTierKey = $serviceTierKey
        AssetRole = ConvertTo-MMITText $assetRoleRaw
        AssetRoleKey = $assetRoleKey
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
        $variable = Get-Variable -Name $name -Scope Global -ErrorAction SilentlyContinue
        if ($null -ne $variable -and (ConvertTo-MMITText $variable.Value) -ne '') {
            return (ConvertTo-MMITText $variable.Value)
        }

        $environmentValue = [Environment]::GetEnvironmentVariable($name)
        if ((ConvertTo-MMITText $environmentValue) -ne '') {
            return (ConvertTo-MMITText $environmentValue)
        }
    }

    return ''
}

function Get-MMITSyncroApiBaseUrl {
    param([string]$ConfiguredBaseUrl)

    $baseUrl = ConvertTo-MMITText $ConfiguredBaseUrl
    if ($baseUrl -eq '') {
        $baseUrl = Get-MMITFirstValue -Names @('SYNCRO_API_BASE_URL', 'SyncroApiBaseUrl')
    }

    if ($baseUrl -eq '') {
        $subdomain = Get-MMITFirstValue -Names @('SYNCRO_SUBDOMAIN', 'SyncroSubdomain')
        if ($subdomain -ne '') {
            $baseUrl = 'https://{0}.syncromsp.com/api/v1' -f (($subdomain -replace '^https?://', '') -replace '\.syncromsp\.com.*$', '')
        }
    }

    return $baseUrl.TrimEnd('/')
}

function Call-SyncroApi {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('GET', 'POST', 'PUT', 'PATCH', 'DELETE')][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        [AllowNull()][object]$Body = $null,
        [hashtable]$Query = @{},
        [switch]$WhatIf
    )

    $baseUrl = Get-MMITSyncroApiBaseUrl -ConfiguredBaseUrl $script:MMITSyncroApiBaseUrl
    $apiKey = ConvertTo-MMITText $script:MMITSyncroApiKey
    if ($apiKey -eq '') {
        $apiKey = Get-MMITFirstValue -Names @('SYNCRO_API_KEY', 'SyncroApiKey')
    }

    if ($baseUrl -eq '') {
        throw 'Syncro API base URL is missing. Set SYNCRO_API_BASE_URL or SYNCRO_SUBDOMAIN.'
    }

    if ($apiKey -eq '') {
        throw 'Syncro API key is missing. Set SYNCRO_API_KEY or pass -SyncroApiKey.'
    }

    $relativePath = $Path.TrimStart('/')
    $uriBuilder = [System.UriBuilder]::new(('{0}/{1}' -f $baseUrl, $relativePath))
    if ($Query.Count -gt 0) {
        $queryParts = New-Object System.Collections.Generic.List[string]
        foreach ($key in $Query.Keys) {
            if ($null -ne $Query[$key]) {
                $queryParts.Add(('{0}={1}' -f [System.Uri]::EscapeDataString([string]$key), [System.Uri]::EscapeDataString([string]$Query[$key])))
            }
        }
        $uriBuilder.Query = ($queryParts -join '&')
    }

    $headers = @{
        Authorization = ('Bearer {0}' -f $apiKey)
        Accept = 'application/json'
    }

    if ($WhatIf) {
        Write-Output ('WHATIF Syncro API {0} {1}' -f $Method, $uriBuilder.Uri.AbsoluteUri)
        if ($null -ne $Body) {
            Write-Output ($Body | ConvertTo-Json -Depth 12)
        }
        return [ordered]@{ what_if = $true; method = $Method; path = $relativePath }
    }

    $invokeArgs = @{
        Method = $Method
        Uri = $uriBuilder.Uri.AbsoluteUri
        Headers = $headers
        ContentType = 'application/json'
        ErrorAction = 'Stop'
    }

    if ($null -ne $Body) {
        $invokeArgs.Body = ($Body | ConvertTo-Json -Depth 12)
    }

    return Invoke-RestMethod @invokeArgs
}

function ConvertFrom-MMITSyncroCustomFieldList {
    param([AllowNull()][object]$CustomFields)

    $fields = @{}
    if ($null -eq $CustomFields) {
        return $fields
    }

    if ($CustomFields -is [System.Collections.IDictionary]) {
        foreach ($key in $CustomFields.Keys) {
            $fields[[string]$key] = $CustomFields[$key]
        }
        return $fields
    }

    foreach ($field in @($CustomFields)) {
        $name = ''
        $value = $null
        if ($field -is [System.Collections.IDictionary]) {
            if ($field.Contains('name')) { $name = ConvertTo-MMITText $field['name'] }
            if ($name -eq '' -and $field.Contains('field_name')) { $name = ConvertTo-MMITText $field['field_name'] }
            if ($name -eq '' -and $field.Contains('label')) { $name = ConvertTo-MMITText $field['label'] }
            if ($field.Contains('value')) { $value = $field['value'] }
        } else {
            if ($null -ne $field.PSObject.Properties['name']) { $name = ConvertTo-MMITText $field.name }
            if ($name -eq '' -and $null -ne $field.PSObject.Properties['field_name']) { $name = ConvertTo-MMITText $field.field_name }
            if ($name -eq '' -and $null -ne $field.PSObject.Properties['label']) { $name = ConvertTo-MMITText $field.label }
            if ($null -ne $field.PSObject.Properties['value']) { $value = $field.value }
        }

        if ($name -ne '') {
            $fields[$name] = $value
        }
    }

    return $fields
}

function Get-MMITAssetId {
    param([string]$ConfiguredAssetId)

    $id = ConvertTo-MMITText $ConfiguredAssetId
    if ($id -ne '') {
        return $id
    }

    return Get-MMITFirstValue -Names @(
        'SYNCRO_ASSET_ID',
        'ASSET_ID',
        'asset_id',
        'AssetId',
        'SyncroAssetId',
        'customer_asset_id',
        'CustomerAssetId'
    )
}

function Get-MMITCustomerId {
    param(
        [string]$ConfiguredCustomerId,
        [AllowNull()][object]$Asset
    )

    $id = ConvertTo-MMITText $ConfiguredCustomerId
    if ($id -ne '') {
        return $id
    }

    $id = Get-MMITFirstValue -Names @('SYNCRO_CUSTOMER_ID', 'CUSTOMER_ID', 'customer_id', 'CustomerId', 'SyncroCustomerId')
    if ($id -ne '') {
        return $id
    }

    if ($null -ne $Asset) {
        foreach ($propertyName in @('customer_id', 'customerId', 'CustomerId')) {
            if ($null -ne $Asset.PSObject.Properties[$propertyName]) {
                $id = ConvertTo-MMITText $Asset.$propertyName
                if ($id -ne '') {
                    return $id
                }
            }
        }
    }

    return ''
}

function Get-MMITSyncroAsset {
    param([Parameter(Mandatory = $true)][string]$AssetId)

    $response = Call-SyncroApi -Method GET -Path ('customer_assets/{0}' -f $AssetId) -WhatIf:$script:MMITWhatIfSyncro
    if ($null -ne $response.PSObject.Properties['asset']) {
        return $response.asset
    }
    if ($null -ne $response.PSObject.Properties['customer_asset']) {
        return $response.customer_asset
    }
    return $response
}

function Get-MMITAssetCustomFields {
    param([AllowNull()][object]$Asset)

    $fields = @{}
    if ($null -ne $Asset) {
        foreach ($propertyName in @('properties', 'custom_fields', 'custom_fields_values', 'custom_field_values')) {
            if ($null -ne $Asset.PSObject.Properties[$propertyName]) {
                $fields = ConvertFrom-MMITSyncroCustomFieldList -CustomFields $Asset.$propertyName
                if ($fields.Count -gt 0) {
                    return $fields
                }
            }
        }
    }

    foreach ($fieldName in $script:MMITRequiredCustomFields) {
        $value = Get-MMITFirstValue -Names @($fieldName, ($fieldName -replace '[^A-Za-z0-9]+', '_').ToUpperInvariant())
        if ($value -ne '') {
            $fields[$fieldName] = $value
        }
    }

    return $fields
}

function Update-MMITSyncroAssetCustomFields {
    param(
        [Parameter(Mandatory = $true)][string]$AssetId,
        [Parameter(Mandatory = $true)][hashtable]$CustomFields
    )

    $body = @{
        asset = @{
            properties = $CustomFields
        }
    }

    return Call-SyncroApi -Method PUT -Path ('customer_assets/{0}' -f $AssetId) -Body $body -WhatIf:$script:MMITWhatIfSyncro
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
            $displayName = ConvertTo-MMITText $item.DisplayName
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
        [string]$AssetId
    )

    $path = ConvertTo-MMITText $ConfiguredMarkerPath
    if ($path -ne '') {
        return $path
    }

    $safeAssetId = if ((ConvertTo-MMITText $AssetId) -ne '') { ($AssetId -replace '[^A-Za-z0-9_.-]+', '_') } else { 'unknown-asset' }
    return (Join-Path $env:ProgramData ('MMIT\OnboardingAcceptance\ready-ticket-{0}.marker' -f $safeAssetId))
}

function Test-MMITReadyTicketMarker {
    param([Parameter(Mandatory = $true)][string]$Path)

    return (Test-Path -LiteralPath $Path)
}

function Set-MMITReadyTicketMarker {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$TicketId
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

function New-MMITReadyMoveTicket {
    param(
        [Parameter(Mandatory = $true)][string]$AssetId,
        [AllowNull()][string]$CustomerId,
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Decision
    )

    $subject = $script:MMITReadyTicketSubject
    $bodyText = @(
        'MMIT onboarding acceptance is READY.',
        '',
        ('Asset ID: {0}' -f $AssetId),
        ('Customer ID: {0}' -f (ConvertTo-MMITText $CustomerId)),
        '',
        'Do not move assets from this acceptance script. Use the production move/deploy process after reviewing the target folder.',
        '',
        $Decision.Summary
    ) -join [Environment]::NewLine

    $ticket = @{
        subject = $subject
        problem_type = 'Onboarding'
        status = 'New'
        priority = 'Normal'
        comment = @{
            body = $bodyText
            hidden = $false
            do_not_email = $true
        }
        custom_fields = @{
            'MMIT Onboarding Status' = $Decision.Status
            'MMIT Ready To Move' = $Decision.ReadyToMove
        }
    }

    if ((ConvertTo-MMITText $CustomerId) -ne '') {
        $ticket.customer_id = $CustomerId
    }
    if ((ConvertTo-MMITText $AssetId) -ne '') {
        $ticket.asset_id = $AssetId
        $ticket.customer_asset_id = $AssetId
    }

    $response = Call-SyncroApi -Method POST -Path 'tickets' -Body @{ ticket = $ticket } -WhatIf:$script:MMITWhatIfSyncro
    if ($null -ne $response.PSObject.Properties['ticket'] -and $null -ne $response.ticket.PSObject.Properties['id']) {
        return (ConvertTo-MMITText $response.ticket.id)
    }
    if ($null -ne $response.PSObject.Properties['id']) {
        return (ConvertTo-MMITText $response.id)
    }
    if ($script:MMITWhatIfSyncro) {
        return 'WHATIF'
    }
    return ''
}

function Write-MMITOnboardingAcceptanceResult {
    param(
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Decision,
        [Parameter(Mandatory = $true)][string]$AssetId,
        [AllowNull()][string]$CustomerId,
        [string]$MarkerPath
    )

    Write-Output $Decision.Summary

    $fieldUpdates = @{
        'MMIT Onboarding Status' = $Decision.Status
        'MMIT Ready To Move' = $Decision.ReadyToMove
        'MMIT Onboarding Result' = $Decision.Summary
    }
    Update-MMITSyncroAssetCustomFields -AssetId $AssetId -CustomFields $fieldUpdates | Out-Null

    if ($Decision.Status -ne 'READY') {
        Write-Output 'Asset is not READY; ready/move ticket was not created.'
        return
    }

    if (Test-MMITReadyTicketMarker -Path $MarkerPath) {
        Write-Output ('Ready/move ticket already created; marker exists at {0}.' -f $MarkerPath)
        return
    }

    $ticketId = New-MMITReadyMoveTicket -AssetId $AssetId -CustomerId $CustomerId -Decision $Decision
    if ($script:MMITWhatIfSyncro) {
        Write-Output ('WHATIF ready/move ticket would be marked once. Ticket ID: {0}. Marker: {1}' -f $ticketId, $MarkerPath)
        return
    }

    Set-MMITReadyTicketMarker -Path $MarkerPath -TicketId $ticketId
    Write-Output ('Ready/move ticket created once. Ticket ID: {0}. Marker: {1}' -f $ticketId, $MarkerPath)
}

function Invoke-MMITOnboardingAcceptance {
    param(
        [string]$SyncroApiBaseUrl,
        [string]$SyncroApiKey,
        [string]$AssetId,
        [string]$CustomerId,
        [string]$MarkerPath,
        [string]$ReadyTicketSubject,
        [switch]$WhatIfSyncro
    )

    $script:MMITSyncroApiBaseUrl = $SyncroApiBaseUrl
    $script:MMITSyncroApiKey = $SyncroApiKey
    $script:MMITWhatIfSyncro = [bool]$WhatIfSyncro
    $script:MMITReadyTicketSubject = if ((ConvertTo-MMITText $ReadyTicketSubject) -ne '') { $ReadyTicketSubject } else { 'MMIT onboarding acceptance ready to move' }

    $resolvedAssetId = Get-MMITAssetId -ConfiguredAssetId $AssetId
    if ($resolvedAssetId -eq '') {
        throw 'Unable to determine Syncro asset ID. Set SYNCRO_ASSET_ID, ASSET_ID, or pass -AssetId.'
    }

    $asset = Get-MMITSyncroAsset -AssetId $resolvedAssetId
    $customFields = Get-MMITAssetCustomFields -Asset $asset
    $resolvedCustomerId = Get-MMITCustomerId -ConfiguredCustomerId $CustomerId -Asset $asset
    $localChecks = Get-MMITLocalCheckResults
    $decision = Get-MMITOnboardingDecision -CustomFields $customFields -CheckResults $localChecks
    $resolvedMarkerPath = Get-MMITMarkerPath -ConfiguredMarkerPath $MarkerPath -AssetId $resolvedAssetId

    Write-MMITOnboardingAcceptanceResult -Decision $decision -AssetId $resolvedAssetId -CustomerId $resolvedCustomerId -MarkerPath $resolvedMarkerPath
}

if ($MyInvocation.InvocationName -ne '.') {
    Invoke-MMITOnboardingAcceptance `
        -SyncroApiBaseUrl $SyncroApiBaseUrl `
        -SyncroApiKey $SyncroApiKey `
        -AssetId $AssetId `
        -CustomerId $CustomerId `
        -MarkerPath $MarkerPath `
        -ReadyTicketSubject $ReadyTicketSubject `
        -WhatIfSyncro:$WhatIfSyncro
}
