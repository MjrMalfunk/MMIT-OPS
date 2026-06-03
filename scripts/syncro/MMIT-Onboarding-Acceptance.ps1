# MMIT-Onboarding-Acceptance.ps1
# Route-aware acceptance helpers for the Syncro asset onboarding script.
#
# This file intentionally keeps Syncro transport separate from acceptance
# decisions. Existing Syncro API, ticket creation, marker/idempotency, custom
# field update, and deploy/folder move code should continue calling these
# helpers, then use the existing write methods with the returned status,
# ready-to-move flag, result summary, and failure list.

Set-StrictMode -Version 2.0

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
    $isWorkstation = $assetRoleKey -eq 'workstation'
    $isServer = $assetRoleKey -eq 'server'
    $isManage = $serviceTierKey -eq 'manageit'
    $isProtect = $serviceTierKey -eq 'protectit'
    $isGovern = $serviceTierKey -eq 'governit'

    $huntressRequired = ($isProtect -or $isGovern)
    $scoutDnsRequired = $dnsRequired -or ($isProtect -or $isGovern)

    if ($isServer) {
        $coveRequired = $backupRequired
    } elseif ($isWorkstation -and ($isProtect -or $isGovern)) {
        $coveRequired = $true
    } else {
        $coveRequired = $backupRequired
    }

    $huntressReason = if ($huntressRequired) { 'required for Protect/Govern IT' } else { "not required for $((ConvertTo-MMITText $serviceTierRaw))" }
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

function Write-MMITOnboardingAcceptanceResult {
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Decision)

    Write-Output $Decision.Summary

    # Call the existing Syncro custom-field update method in the main script:
    # - MMIT Onboarding Status = $Decision.Status
    # - MMIT Ready To Move = $Decision.ReadyToMove
    # - MMIT Onboarding Result = $Decision.Summary
    #
    # If $Decision.Status is READY, call the existing ready/move ticket creation
    # method guarded by the existing marker/idempotency behavior. Do not move the
    # asset here; deploy/folder movement remains separate.
}
