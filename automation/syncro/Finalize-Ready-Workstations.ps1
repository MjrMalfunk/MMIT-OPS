# MMIT Finalize Ready Workstations
#
# ADMIN RUNNER ONLY:
# This script is intended to run only from trusted MMIT automation/admin
# workstations that are explicitly listed in $AllowedRunnerNames. It must never
# be deployed to or executed from client endpoints. Do not embed Syncro API
# tokens or other secrets in this script; the API token is read from the
# SYNCRO_API_TOKEN Machine environment variable on the trusted runner host.
#
# Design notes:
# - Workstation lane is processed first and is the only lane allowed to move.
# - Server lane folder IDs are included for parity with onboarding/mover design,
#   but server moves remain disabled/dry-run-only until a separate task enables
#   them.
# - Ticket close/comment handling intentionally remains out of scope.

[CmdletBinding()]
param(
    [bool]$DryRun = $true,
    [int]$MaxPages = 50,
    [int]$PageSize = 100
)

$ErrorActionPreference = "Stop"

# -----------------------------
# CONFIG
# -----------------------------

$AllowedRunnerNames = @(
    "MJRSBEAST"
)

$Subdomain = "midwestmanagedit"

# Current Syncro policy folder IDs.
$DeployWorkstationsFolderId     = 5027867
$ProductionWorkstationsFolderId = 5027864
$DeployServersFolderId          = 5027868
$ProductionServersFolderId      = 5027865

# Syncro select-box option IDs for MMIT custom fields.
$ReadyStatusValue = 135355 # MMIT Onboarding Status = READY
$ReadyMoveValue   = 135359 # MMIT Ready To Move = Yes

$ExpectedWorkstationTarget = "Production/Workstations"
$ExpectedServerTarget      = "Production/Servers"
$ServerMovesEnabled        = $false

$LogDir  = "C:\ProgramData\MMIT\Logs"
$LogFile = Join-Path $LogDir "MMIT-Finalize-Ready-Workstations.log"
$LockFile = "C:\ProgramData\MMIT\Automation\Finalize-Ready-Workstations.lock"

New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
New-Item -ItemType Directory -Path (Split-Path -Path $LockFile -Parent) -Force | Out-Null

function Write-Log {
    param([string]$Message)

    $Line = "{0} {1}" -f (Get-Date).ToUniversalTime().ToString("s"), $Message
    Write-Host $Line
    Add-Content -Path $LogFile -Value $Line
}

function ConvertTo-LogValue {
    param([object]$Value)

    if ($null -eq $Value) {
        return "<empty>"
    }

    $Text = ([string]$Value).Trim()
    if ([string]::IsNullOrWhiteSpace($Text)) {
        return "<empty>"
    }

    return $Text
}

function Get-ObjectPropertyValue {
    param(
        [object]$Object,
        [string[]]$Names
    )

    if ($null -eq $Object) {
        return $null
    }

    foreach ($Name in $Names) {
        if ($Object -is [System.Collections.IDictionary] -and $Object.Contains($Name)) {
            return $Object[$Name]
        }

        if ($Object.PSObject.Properties.Name -contains $Name) {
            return $Object.PSObject.Properties[$Name].Value
        }
    }

    return $null
}

function Get-AssetProperty {
    param(
        [object]$Asset,
        [string]$Name
    )

    $FieldCollections = @(
        (Get-ObjectPropertyValue -Object $Asset -Names @("properties")),
        (Get-ObjectPropertyValue -Object $Asset -Names @("custom_fields")),
        (Get-ObjectPropertyValue -Object $Asset -Names @("custom_field_values"))
    )

    foreach ($Collection in $FieldCollections) {
        if ($null -eq $Collection) {
            continue
        }

        if ($Collection -is [System.Collections.IDictionary] -and $Collection.Contains($Name)) {
            return Resolve-CustomFieldValue -FieldValue $Collection[$Name]
        }

        if ($Collection.PSObject.Properties.Name -contains $Name) {
            return Resolve-CustomFieldValue -FieldValue $Collection.PSObject.Properties[$Name].Value
        }

        foreach ($Entry in @($Collection)) {
            $EntryName = Get-ObjectPropertyValue -Object $Entry -Names @("name", "field_name", "label")
            if ([string]$EntryName -ne $Name) {
                continue
            }

            $Value = Get-ObjectPropertyValue -Object $Entry -Names @("value", "display_value", "text", "key", "id")
            return Resolve-CustomFieldValue -FieldValue $Value
        }
    }

    return $null
}

function Resolve-CustomFieldValue {
    param([object]$FieldValue)

    if ($null -eq $FieldValue) {
        return $null
    }

    if ($FieldValue -is [string] -or $FieldValue -is [int] -or $FieldValue -is [long] -or $FieldValue -is [bool]) {
        return $FieldValue
    }

    foreach ($Name in @("value", "display_value", "text", "key", "id")) {
        $Value = Get-ObjectPropertyValue -Object $FieldValue -Names @($Name)
        if ($null -ne $Value) {
            return $Value
        }
    }

    return $FieldValue
}

function Get-AssetsFromResponse {
    param([object]$Response)

    $RawAssets = Get-ObjectPropertyValue -Object $Response -Names @(
        "customer_assets",
        "assets",
        "data"
    )

    if ($null -eq $RawAssets) {
        return @()
    }

    if ($RawAssets -is [string]) {
        return @()
    }

    if ($RawAssets -is [System.Collections.IDictionary]) {
        if ($RawAssets.Contains("id") -or $RawAssets.Contains("asset_id")) {
            return @($RawAssets)
        }

        return @()
    }

    if ($RawAssets -is [System.Array]) {
        return @($RawAssets | Where-Object { $null -ne $_ })
    }

    if ($RawAssets -is [System.Collections.IEnumerable]) {
        return @($RawAssets | Where-Object { $null -ne $_ })
    }

    if (Get-ObjectPropertyValue -Object $RawAssets -Names @("id", "asset_id")) {
        return @($RawAssets)
    }

    return @()
}

function Get-SingleAssetFromResponse {
    param([object]$Response)

    foreach ($Name in @("asset", "customer_asset", "data")) {
        $Asset = Get-ObjectPropertyValue -Object $Response -Names @($Name)
        if ($Asset) {
            return $Asset
        }
    }

    return $Response
}

function Get-AssetFolderId {
    param([object]$Asset)

    $Value = Get-ObjectPropertyValue -Object $Asset -Names @("policy_folder_id", "policyFolderId", "folder_id")
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string]$Value)) {
        return $null
    }

    return [int]$Value
}

function Get-AssetId {
    param([object]$Asset)

    $Value = Get-ObjectPropertyValue -Object $Asset -Names @("id", "asset_id", "customer_asset_id")
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string]$Value)) {
        return $null
    }

    return [int]$Value
}

function Get-AssetName {
    param([object]$Asset)

    $Value = Get-ObjectPropertyValue -Object $Asset -Names @("name", "asset_name", "hostname")
    if ([string]::IsNullOrWhiteSpace([string]$Value)) {
        return "<unnamed asset>"
    }

    return [string]$Value
}

function Invoke-SyncroGet {
    param([string]$Path)

    $Uri = "$BaseUrl/$Path"
    return Invoke-RestMethod -Method Get -Uri $Uri -Headers $Headers
}

function Invoke-SyncroAssetPut {
    param(
        [int]$AssetId,
        [hashtable]$Payload
    )

    $Uri = "$BaseUrl/customer_assets/$AssetId"
    $Body = $Payload | ConvertTo-Json -Depth 8
    return Invoke-RestMethod -Method Put -Uri $Uri -Headers $Headers -Body $Body
}

function Move-SyncroAsset {
    param(
        [int]$AssetId,
        [int]$TargetPolicyFolderId
    )

    return Invoke-SyncroAssetPut -AssetId $AssetId -Payload @{
        policy_folder_id = $TargetPolicyFolderId
    }
}

function Write-SyncroMoveResult {
    param(
        [int]$AssetId,
        [string]$Result,
        [datetime]$CompletedAtUtc
    )

    return Invoke-SyncroAssetPut -AssetId $AssetId -Payload @{
        properties = @{
            "MMIT Auto Move Result"       = $Result
            "MMIT Onboarding Completed At" = $CompletedAtUtc.ToString("o")
        }
    }
}

function Test-ReadyValue {
    param(
        [object]$Actual,
        [int]$ExpectedOptionId,
        [string[]]$ExpectedText
    )

    $ActualText = ([string]$Actual).Trim()
    if ([string]$ActualText -eq [string]$ExpectedOptionId) {
        return $true
    }

    foreach ($Text in $ExpectedText) {
        if ($ActualText.Equals($Text, [System.StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }

    return $false
}

function Test-TargetValue {
    param(
        [object]$Actual,
        [string]$ExpectedTarget
    )

    $ActualText = ([string]$Actual).Trim()
    return $ActualText.Equals($ExpectedTarget, [System.StringComparison]::OrdinalIgnoreCase)
}

function Get-SkipReason {
    param(
        [object]$Asset,
        [int]$ExpectedDeployFolderId,
        [string]$ExpectedTarget
    )

    $CurrentFolderId = Get-AssetFolderId -Asset $Asset
    if ($CurrentFolderId -ne $ExpectedDeployFolderId) {
        return "folder mismatch current=$(ConvertTo-LogValue $CurrentFolderId) expected=$ExpectedDeployFolderId"
    }

    $Status = Get-AssetProperty -Asset $Asset -Name "MMIT Onboarding Status"
    if (-not (Test-ReadyValue -Actual $Status -ExpectedOptionId $ReadyStatusValue -ExpectedText @("READY"))) {
        return "MMIT Onboarding Status not READY current=$(ConvertTo-LogValue $Status) expected_option=$ReadyStatusValue"
    }

    $Ready = Get-AssetProperty -Asset $Asset -Name "MMIT Ready To Move"
    if (-not (Test-ReadyValue -Actual $Ready -ExpectedOptionId $ReadyMoveValue -ExpectedText @("Yes"))) {
        return "MMIT Ready To Move not Yes current=$(ConvertTo-LogValue $Ready) expected_option=$ReadyMoveValue"
    }

    $Target = Get-AssetProperty -Asset $Asset -Name "MMIT Production Folder Target"
    if (-not (Test-TargetValue -Actual $Target -ExpectedTarget $ExpectedTarget)) {
        return "MMIT Production Folder Target mismatch current=$(ConvertTo-LogValue $Target) expected=$ExpectedTarget"
    }

    return $null
}

function Find-ReadyAssetsForLane {
    param(
        [string]$LaneName,
        [int]$DeployFolderId,
        [int]$ProductionFolderId,
        [string]$ExpectedTarget,
        [bool]$MovesEnabled
    )

    Write-Log "Lane $LaneName scan started. deploy_folder=$DeployFolderId production_folder=$ProductionFolderId expected_target=$ExpectedTarget moves_enabled=$MovesEnabled"
    $Candidates = @()
    $PagesScanned = 0
    $AssetsScanned = 0
    $AssetsSkipped = 0

    for ($Page = 1; $Page -le $MaxPages; $Page++) {
        $Path = "customer_assets?page=$Page&per_page=$PageSize"
        Write-Log "Scanning $LaneName customer_assets page $Page path=$Path"
        $Response = Invoke-SyncroGet -Path $Path
        $Assets = Get-AssetsFromResponse -Response $Response
        $PagesScanned++

        if (!$Assets -or $Assets.Count -eq 0) {
            Write-Log "Scanned $LaneName page ${Page}: no assets returned; stopping lane scan. pages_scanned=$PagesScanned assets_scanned=$AssetsScanned skipped=$AssetsSkipped candidates=$($Candidates.Count)"
            break
        }

        Write-Log "Scanned $LaneName page ${Page}: assets_returned=$($Assets.Count) pages_scanned=$PagesScanned"

        foreach ($Asset in $Assets) {
            $AssetsScanned++
            $AssetId = Get-AssetId -Asset $Asset
            $AssetName = Get-AssetName -Asset $Asset
            $SkipReason = Get-SkipReason -Asset $Asset -ExpectedDeployFolderId $DeployFolderId -ExpectedTarget $ExpectedTarget

            if ($SkipReason) {
                $AssetsSkipped++
                Write-Log "Skipped $LaneName asset='$AssetName' id=$(ConvertTo-LogValue $AssetId): $SkipReason"
                continue
            }

            Write-Log "Candidate found lane=$LaneName asset='$AssetName' id=$AssetId current_folder=$DeployFolderId target_folder=$ProductionFolderId"
            $Candidates += $Asset
        }
    }

    Write-Log "Lane $LaneName scan completed. pages_scanned=$PagesScanned assets_scanned=$AssetsScanned skipped=$AssetsSkipped candidates=$($Candidates.Count)"
    return $Candidates
}

try {
    Write-Log "==== MMIT Finalize Ready Workstations Started dry_run=$DryRun ===="

    if ($AllowedRunnerNames -notcontains $env:COMPUTERNAME) {
        throw "This admin runner is only allowed on trusted MMIT automation hosts. Current machine: $env:COMPUTERNAME"
    }

    if (Test-Path $LockFile) {
        $LockAge = (Get-Date) - (Get-Item $LockFile).LastWriteTime

        if ($LockAge.TotalMinutes -lt 30) {
            Write-Log "Another finalizer run appears active. Exiting. lock_file=$LockFile lock_age_minutes=$([math]::Round($LockAge.TotalMinutes, 2))"
            exit 0
        }

        Write-Log "Stale lock detected. Removing. lock_file=$LockFile lock_age_minutes=$([math]::Round($LockAge.TotalMinutes, 2))"
        Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    }

    New-Item -ItemType File -Path $LockFile -Force | Out-Null
    Write-Log "Lock acquired. lock_file=$LockFile"

    $ApiToken = [Environment]::GetEnvironmentVariable("SYNCRO_API_TOKEN", "Machine")

    if ([string]::IsNullOrWhiteSpace($ApiToken)) {
        throw "SYNCRO_API_TOKEN Machine environment variable is missing. Configure it only on trusted MMIT automation hosts."
    }

    $BaseUrl = "https://$Subdomain.syncromsp.com/api/v1"

    $Headers = @{
        "Authorization" = "Bearer $ApiToken"
        "Accept"        = "application/json"
        "Content-Type"  = "application/json"
    }

    Write-Log "Using Syncro public API route PUT /api/v1/customer_assets/{asset_id} with policy_folder_id for folder moves. token_source=Machine environment variable token_logged=false"

    # Workstation lane stays first and is currently the only lane eligible for live moves.
    $WorkstationCandidates = Find-ReadyAssetsForLane -LaneName "workstations" -DeployFolderId $DeployWorkstationsFolderId -ProductionFolderId $ProductionWorkstationsFolderId -ExpectedTarget $ExpectedWorkstationTarget -MovesEnabled $true

    # Server lane is intentionally disabled/dry-run-only. This keeps IDs visible for
    # onboarding parity without enabling server auto-moves in this task.
    $ServerCandidates = Find-ReadyAssetsForLane -LaneName "servers-disabled" -DeployFolderId $DeployServersFolderId -ProductionFolderId $ProductionServersFolderId -ExpectedTarget $ExpectedServerTarget -MovesEnabled $ServerMovesEnabled
    foreach ($Asset in $ServerCandidates) {
        Write-Log "Server candidate remains disabled/dry-run-only; no move attempted asset='$(Get-AssetName -Asset $Asset)' id=$(Get-AssetId -Asset $Asset) target_folder=$ProductionServersFolderId"
    }

    if ($WorkstationCandidates.Count -eq 0) {
        Write-Log "MMIT_AUTO_MOVE_STATUS: NO_READY_WORKSTATIONS_FOUND"
    }

    foreach ($Asset in $WorkstationCandidates) {
        $AssetId = Get-AssetId -Asset $Asset
        $AssetName = Get-AssetName -Asset $Asset

        if ($null -eq $AssetId) {
            Write-Log "Skipped workstation candidate asset='$AssetName': missing asset id"
            continue
        }

        if ($DryRun) {
            Write-Log "DRY RUN MOVE: would move workstation asset='$AssetName' id=$AssetId from policy_folder_id=$DeployWorkstationsFolderId to policy_folder_id=$ProductionWorkstationsFolderId"
            continue
        }

        Write-Log "ACTUAL MOVE: moving workstation asset='$AssetName' id=$AssetId from policy_folder_id=$DeployWorkstationsFolderId to policy_folder_id=$ProductionWorkstationsFolderId"
        Move-SyncroAsset -AssetId $AssetId -TargetPolicyFolderId $ProductionWorkstationsFolderId | Out-Null

        Start-Sleep -Seconds 5

        Write-Log "POST-MOVE VERIFICATION: fetching workstation asset='$AssetName' id=$AssetId"
        $Verify = Invoke-SyncroGet -Path "customer_assets/$AssetId"
        $VerifiedAsset = Get-SingleAssetFromResponse -Response $Verify
        $NewFolderId = Get-AssetFolderId -Asset $VerifiedAsset

        if ($NewFolderId -eq $ProductionWorkstationsFolderId) {
            $MoveCompletedAtUtc = (Get-Date).ToUniversalTime()
            $Result = "MOVED_TO_PRODUCTION policy_folder_id=$ProductionWorkstationsFolderId verified_at=$($MoveCompletedAtUtc.ToString('o'))"
            Write-Log "POST-MOVE VERIFICATION: confirmed workstation asset='$AssetName' id=$AssetId policy_folder_id=$NewFolderId"
            Write-Log "MMIT_AUTO_MOVE_STATUS: MOVED_TO_PRODUCTION asset='$AssetName' id=$AssetId"

            try {
                Write-SyncroMoveResult -AssetId $AssetId -Result $Result -CompletedAtUtc $MoveCompletedAtUtc | Out-Null
                Write-Log "WRITE-BACK: wrote MMIT Auto Move Result and MMIT Onboarding Completed At asset='$AssetName' id=$AssetId"
            }
            catch {
                Write-Log "WRITE-BACK WARNING: move verified, but Syncro field write-back failed asset='$AssetName' id=$AssetId error=$($_.Exception.Message)"
            }
        }
        else {
            Write-Log "POST-MOVE VERIFICATION: failed workstation asset='$AssetName' id=$AssetId expected_policy_folder_id=$ProductionWorkstationsFolderId actual_policy_folder_id=$(ConvertTo-LogValue $NewFolderId)"
            Write-Log "MMIT_AUTO_MOVE_STATUS: MOVE_NOT_CONFIRMED asset='$AssetName' id=$AssetId new_folder=$(ConvertTo-LogValue $NewFolderId)"
        }
    }

    Write-Log "==== MMIT Finalize Ready Workstations Completed ===="
}
catch {
    Write-Log "MMIT_AUTO_MOVE_STATUS: FAILED"
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
finally {
    Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    Write-Log "Lock released. lock_file=$LockFile"
}
