param(
    [Parameter(Mandatory=$true)]
    [string]$DatabaseId,
    [string]$ConfigPath,
    [switch]$CheckOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$scriptRoot = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
. (Join-Path $scriptRoot 'ibBackupToolShared.ps1')

if (-not $ConfigPath) {
    $ConfigPath = Join-Path $scriptRoot 'ib-config.json'
}

$config = Get-Config -Path $ConfigPath

$db = $null
foreach ($item in $config.databases) {
    if ($item.id -eq $DatabaseId) {
        $db = $item
        break
    }
}
if (-not $db) {
    throw "Database id not found in config: $DatabaseId"
}

$prod = $config.environments.prod
$test = $config.environments.test
$backupDir = $config.backupDir

$gbakPath = Resolve-Tool -ConfiguredPath $config.tools.gbak -ToolName 'gbak.exe'
$gfixPath = Resolve-Tool -ConfiguredPath $config.tools.gfix -ToolName 'gfix.exe'

$gbakExists = [bool]$gbakPath
$gfixExists = [bool]$gfixPath
$backupDirExists = $false
$testPathExists = $false

if ($backupDir) {
    if (-not (Test-Path $backupDir)) {
        try {
            New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        } catch {
            # ignore, handled later
        }
    }
    $backupDirExists = Test-Path $backupDir
}

if ($db.testPath) {
    $testPathExists = Test-Path $db.testPath
}

$prodReachable = $null
if ($prod.host) {
    try {
        $prodReachable = Test-Connection -ComputerName $prod.host -Count 1 -Quiet -ErrorAction Stop
    } catch {
        $prodReachable = $false
    }
}

$canReplicate = $gbakExists -and $gfixExists -and $backupDirExists -and $db.prodPath -and $db.testPath

$status = [ordered]@{
    databaseId      = $DatabaseId
    databaseName    = $db.name
    prodHost        = $prod.host
    testHost        = $test.host
    prodPath        = $db.prodPath
    testPath        = $db.testPath
    backupDir       = $backupDir
    tools           = [ordered]@{
        gbak      = $gbakPath
        gfix      = $gfixPath
        gbakExists = $gbakExists
        gfixExists = $gfixExists
    }
    backupDirExists = $backupDirExists
    testPathExists  = $testPathExists
    prodReachable   = $prodReachable
    canReplicate    = $canReplicate
    message         = if ($canReplicate) { 'Ready to replicate' } else { 'Missing prerequisites.' }
}

if ($CheckOnly) {
    $status | ConvertTo-Json -Depth 6 | Write-Output
    exit 0
}

if (-not $canReplicate) {
    throw 'Missing prerequisites for replication. Run status check for details.'
}

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$script:LogFile = Join-Path $backupDir ("IBRefresh_{0}_{1}.log" -f $DatabaseId, $timestamp)
$backupFile = Join-Path $backupDir ("{0}_fromPROD_{1}.fbk" -f $db.name, $timestamp)

Write-Log "=== InterBase Refresh $($db.name) started $(Get-Date) ==="
$steps = @()
$started = Get-Date

function Add-Step {
    param(
        [string]$Message
    )
    $step = [ordered]@{ message = $Message; success = $false }
    $script:steps += $step
    return ($script:steps.Count - 1)
}

function Complete-Step {
    param(
        [int]$Index,
        [bool]$Success,
        [string]$Append = ''
    )
    $script:steps[$Index].success = $Success
    if ($Append) {
        $script:steps[$Index].message = "$($script:steps[$Index].message) $Append"
    }
}

try {
    $stepIndex = Add-Step 'Backing up PROD database with gbak -b'
    $backupArgs = @(
        '-b','-t',
        '-user', $prod.user,
        '-password', $prod.password,
        "$($prod.host)`:$($db.prodPath)",
        $backupFile
    )
    Run-Cmd $gbakPath @backupArgs
    Complete-Step -Index $stepIndex -Success $true -Append "-> $backupFile"

    if (Test-Path $db.testPath) {
        $stepIndex = Add-Step 'Shutting down TEST database'
        $shutArgs = @(
            '-shut','-force','0',
            '-user', $test.user,
            '-password', $test.password,
            "$($test.host)`:$($db.testPath)"
        )
        Run-Cmd $gfixPath @shutArgs
        Complete-Step -Index $stepIndex -Success $true
    }

    $stepIndex = Add-Step 'Restoring backup to TEST with gbak -c -replace_database'
    $restoreArgs = @(
        '-c','-replace_database',
        '-user', $test.user,
        '-password', $test.password,
        $backupFile,
        "$($test.host)`:$($db.testPath)"
    )
    Run-Cmd $gbakPath @restoreArgs
    Complete-Step -Index $stepIndex -Success $true

    $stepIndex = Add-Step 'Bringing TEST database online'
    $onlineArgs = @(
        '-online',
        '-user', $test.user,
        '-password', $test.password,
        "$($test.host)`:$($db.testPath)"
    )
    Run-Cmd $gfixPath @onlineArgs
    Complete-Step -Index $stepIndex -Success $true

    Write-Log "=== SUCCESS $(Get-Date) ==="

    $duration = (Get-Date) - $started
    $result = [ordered]@{
        databaseId   = $DatabaseId
        databaseName = $db.name
        logFile      = $LogFile
        backupFile   = $backupFile
        duration     = [string][System.Math]::Round($duration.TotalSeconds, 2) + ' seconds'
        steps        = $steps
    }
    $result | ConvertTo-Json -Depth 6 | Write-Output
} catch {
    Write-Log "ERROR: $_"
    throw
}

