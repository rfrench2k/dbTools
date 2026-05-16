param(
    [Parameter(Mandatory=$true)]
    [string]$DatabaseId,
    [Parameter(Mandatory=$true)]
    [ValidateSet('prod','test')]
    [string]$Environment,
    [Parameter(Mandatory=$true)]
    [string]$BackupFile,
    [Parameter(Mandatory=$true)]
    [string]$TargetPath,
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

if ([string]::IsNullOrWhiteSpace($TargetPath)) {
    throw 'TargetPath parameter is required.'
}

$envKey = $Environment.ToLowerInvariant()

$config = Get-Config -Path $ConfigPath
$database = $config.databases | Where-Object { $_.id -eq $DatabaseId } | Select-Object -First 1
if (-not $database) {
    throw "Database id not found in config: $DatabaseId"
}

$environments = $config.environments
if (-not $environments.$envKey) {
    throw "Environment not defined in config: $Environment"
}
$envConfig = $environments.$envKey

$gbakPath = Resolve-Tool -ConfiguredPath $config.tools.gbak -ToolName 'gbak.exe'
$backupExists = Test-Path -Path $BackupFile
$backupInfo = $null
if ($backupExists) {
    try {
        $backupInfo = Get-Item -Path $BackupFile -ErrorAction Stop
    } catch {
        $backupInfo = $null
    }
}

$backupSize = if ($backupInfo) { [long]$backupInfo.Length } else { $null }
$backupSizeMb = if ($backupInfo) { [math]::Round($backupInfo.Length / 1MB, 2) } else { $null }

$targetDir = Split-Path -Parent $TargetPath
$targetDirExists = $null
if ($targetDir) {
    try {
        $targetDirExists = Test-Path -Path $targetDir
    } catch {
        $targetDirExists = $null
    }
}

$targetPathExists = $null
try {
    if ($TargetPath) {
        $targetPathExists = Test-Path -Path $TargetPath
    }
} catch {
    $targetPathExists = $null
}

$dbHost = $envConfig.host
$user = $envConfig.user
$password = $envConfig.password

$hostReachable = $null
if ($dbHost) {
    try {
        $hostReachable = Test-Connection -ComputerName $dbHost -Count 1 -Quiet -ErrorAction Stop
    } catch {
        $hostReachable = $false
    }
}

$destination = if ($dbHost) { "$dbHost`:$TargetPath" } else { $TargetPath }
$canRestore = $gbakPath -and $backupExists -and $TargetPath -and $user -and $password

$status = [ordered]@{
    success          = $true
    databaseId       = $DatabaseId
    databaseName     = $database.name
    environment      = $envKey.ToUpperInvariant()
    host             = $dbHost
    destination      = $destination
    targetPath       = $TargetPath
    targetDirExists  = $targetDirExists
    targetPathExists = $targetPathExists
    gbakPath         = $gbakPath
    gbakExists       = [bool]$gbakPath
    backupFile       = $BackupFile
    backupExists     = $backupExists
    backupSize       = $backupSize
    backupSizeMb     = $backupSizeMb
    hostReachable    = $hostReachable
    canRestore       = [bool]$canRestore
    message          = if ($canRestore) { 'Ready to restore backup.' } else { 'Missing prerequisites for restore.' }
}

if ($CheckOnly) {
    $status | ConvertTo-Json -Depth 5 | Write-Output
    exit 0
}

if (-not $canRestore) {
    throw 'Missing prerequisites for restore. Run status check for details.'
}

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$envLabel = if ($envKey -eq 'prod') { 'Prod' } else { 'Test' }
$logDir = $config.backupDir
if (-not $logDir) {
    $logDir = Split-Path -Parent $BackupFile
}
if (-not $logDir) {
    $logDir = $scriptRoot
}
$script:LogFile = Join-Path $logDir ("IBRestore_{0}_{1}_{2}.log" -f $DatabaseId, $envLabel, $timestamp)

Write-Log "=== InterBase Restore $($database.name) [$envLabel] started $(Get-Date) ==="
Write-Log "Backup source: $BackupFile"
Write-Log "Destination: $destination"

$started = Get-Date

try {
    if ($targetPathExists -eq $true) {
        Write-Log "Existing database file will be replaced: $TargetPath"
    }

    $args = @(
        '-rep',
        '-v',
        '-user', $user,
        '-password', $password,
        $BackupFile,
        $destination
    )

    Run-Cmd $gbakPath @args

    $duration = (Get-Date) - $started

    $restoredSize = $null
    try {
        $dbInfo = Get-Item -Path $TargetPath -ErrorAction Stop
        $restoredSize = [long]$dbInfo.Length
    } catch {
        $restoredSize = $null
    }

    $result = [ordered]@{
        success        = $true
        databaseId     = $DatabaseId
        databaseName   = $database.name
        environment    = $envLabel
        backupFile     = $BackupFile
        targetPath     = $TargetPath
        logFile        = $script:LogFile
        duration       = [string][math]::Round($duration.TotalSeconds, 2) + ' seconds'
        restoredSize   = $restoredSize
        restoredSizeMb = if ($restoredSize -ne $null) { [math]::Round($restoredSize / 1MB, 2) } else { $null }
        message        = 'Restore completed successfully.'
    }

    Write-Log "=== SUCCESS $(Get-Date) ==="
    $result | ConvertTo-Json -Depth 5 | Write-Output
} catch {
    Write-Log "ERROR: $_"
    throw
}
