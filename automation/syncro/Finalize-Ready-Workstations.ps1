# MMIT Finalize Ready Workstations
# Runs only on trusted MMIT automation host.
# Finds READY_TO_MOVE workstation assets in Deploy / Workstations
# and moves them to Production / Workstations.

$ErrorActionPreference = "Stop"

# -----------------------------
# CONFIG
# -----------------------------

$AllowedRunnerNames = @(
    "MJRSBEAST"
)

$Subdomain = "midwestmanagedit"

# Folder map
$DeployWorkstationsFolderId     = 5002025
$ProductionWorkstationsFolderId = 4955492

# Syncro select-box option IDs for:
# MMIT Onboarding Status = READY_TO_MOVE
# MMIT Ready To Move = Yes
$ReadyStatusValue = 135355
$ReadyMoveValue   = 135359

$ExpectedTarget = "Workstations"

# First run as dry-run if you want another safety check.
$DryRun = $false

$LogDir  = "C:\ProgramData\MMIT\Logs"
$LogFile = Join-Path $LogDir "MMIT-Finalize-Ready-Workstations.log"
$LockFile = "C:\ProgramData\MMIT\Automation\Finalize-Ready-Workstations.lock"

New-Item -ItemType Directory -Path $LogDir -Force | Out-Null

function Write-Log {
    param([string]$Message)

    $Line = "{0} {1}" -f (Get-Date).ToString("s"), $Message
    Write-Host $Line
    Add-Content -Path $LogFile -Value $Line
}

function Get-AssetProperty {
    param(
        [object]$Asset,
        [string]$Name
    )

    if ($Asset.properties -and $Asset.properties.PSObject.Properties.Name -contains $Name) {
        return $Asset.properties.PSObject.Properties[$Name].Value
    }

    return $null
}

function Get-AssetsFromResponse {
    param([object]$Response)

    if ($Response.assets) {
        return @($Response.assets)
    }

    if ($Response.customer_assets) {
        return @($Response.customer_assets)
    }

    if ($Response.asset) {
        return @($Response.asset)
    }

    return @()
}

function Invoke-SyncroGet {
    param([string]$Path)

    $Uri = "$BaseUrl/$Path"
    return Invoke-RestMethod -Method Get -Uri $Uri -Headers $Headers
}

function Move-SyncroAsset {
    param(
        [int]$AssetId,
        [int]$TargetPolicyFolderId
    )

    $Uri = "$BaseUrl/customer_assets/$AssetId"

    $Body = @{
        policy_folder_id = $TargetPolicyFolderId
    } | ConvertTo-Json -Depth 5

    return Invoke-RestMethod -Method Put -Uri $Uri -Headers $Headers -Body $Body
}

try {
    Write-Log "==== MMIT Finalize Ready Workstations Started ===="

    if ($AllowedRunnerNames -notcontains $env:COMPUTERNAME) {
        throw "This script is only allowed on trusted MMIT automation hosts. Current machine: $env:COMPUTERNAME"
    }

    if (Test-Path $LockFile) {
        $LockAge = (Get-Date) - (Get-Item $LockFile).LastWriteTime

        if ($LockAge.TotalMinutes -lt 30) {
            Write-Log "Another finalizer run appears active. Exiting."
            exit 0
        }

        Write-Log "Stale lock detected. Removing."
        Remove-Item $LockFile -Force -ErrorAction SilentlyContinue
    }

    New-Item -ItemType File -Path $LockFile -Force | Out-Null

    $ApiToken = [Environment]::GetEnvironmentVariable("SYNCRO_API_TOKEN", "Machine")

    if ([string]::IsNullOrWhiteSpace($ApiToken)) {
        throw "SYNCRO_API_TOKEN Machine environment variable is missing."
    }

    $BaseUrl = "https://$Subdomain.syncromsp.com/api/v1"

    $Headers = @{
        "Authorization" = "Bearer $ApiToken"
        "Accept"        = "application/json"
        "Content-Type"  = "application/json"
    }

    $Candidates = @()

    for ($Page = 1; $Page -le 50; $Page++) {
        Write-Log "Scanning customer_assets page $Page..."

        $Response = Invoke-SyncroGet -Path "customer_assets?page=$Page"
        $Assets = Get-AssetsFromResponse -Response $Response

        if (!$Assets -or $Assets.Count -eq 0) {
            Write-Log "No more assets found."
            break
        }

        foreach ($Asset in $Assets) {
            $CurrentFolderId = [int]$Asset.policy_folder_id

            if ($CurrentFolderId -ne $DeployWorkstationsFolderId) {
                continue
            }

            $Status = Get-AssetProperty -Asset $Asset -Name "MMIT Onboarding Status"
            $Ready  = Get-AssetProperty -Asset $Asset -Name "MMIT Ready To Move"
            $Target = Get-AssetProperty -Asset $Asset -Name "MMIT Production Folder Target"

            $StatusMatch = ([string]$Status -eq [string]$ReadyStatusValue -or [string]$Status -eq "READY_TO_MOVE")
            $ReadyMatch  = ([string]$Ready -eq [string]$ReadyMoveValue -or [string]$Ready -eq "Yes")
            $TargetMatch = ($Target -eq $ExpectedTarget)

            if ($StatusMatch -and $ReadyMatch -and $TargetMatch) {
                $Candidates += $Asset
            }
        }
    }

    if ($Candidates.Count -eq 0) {
        Write-Log "MMIT_AUTO_MOVE_STATUS: NO_READY_WORKSTATIONS_FOUND"
        exit 0
    }

    foreach ($Asset in $Candidates) {
        $AssetId = [int]$Asset.id
        $AssetName = $Asset.name

        Write-Log "Ready workstation found: $AssetName / Asset ID $AssetId"

        if ($DryRun) {
            Write-Log "DRY RUN: Would move $AssetName from $DeployWorkstationsFolderId to $ProductionWorkstationsFolderId."
            continue
        }

        Write-Log "Moving $AssetName to production Workstations folder..."
        Move-SyncroAsset -AssetId $AssetId -TargetPolicyFolderId $ProductionWorkstationsFolderId | Out-Null

        Start-Sleep -Seconds 5

        $Verify = Invoke-SyncroGet -Path "customer_assets/$AssetId"
        $NewFolderId = [int]$Verify.asset.policy_folder_id

        if ($NewFolderId -eq $ProductionWorkstationsFolderId) {
            Write-Log "MMIT_AUTO_MOVE_STATUS: MOVED_TO_PRODUCTION asset=$AssetName id=$AssetId"
        }
        else {
            Write-Log "MMIT_AUTO_MOVE_STATUS: MOVE_NOT_CONFIRMED asset=$AssetName id=$AssetId new_folder=$NewFolderId"
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
}
