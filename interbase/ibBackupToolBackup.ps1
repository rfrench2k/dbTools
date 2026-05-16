param(
    [Parameter(Mandatory=$true)]
    [string]$DatabaseId,
    [Parameter(Mandatory=$true)]
    [ValidateSet('prod','test')]
    [string]$Environment,
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

$envKey = $Environment.ToLower()

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

$backupDir = $config.backupDir
if ($backupDir) {
    if (-not (Test-Path $backupDir)) {
        try {
            New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        } catch {
            # ignore, handled later
        }
    }
}

$gbakPath = Resolve-Tool -ConfiguredPath $config.tools.gbak -ToolName 'gbak.exe'
$backupDirExists = $false
$backupDirWritable = $false
if ($backupDir) {
    $backupDirExists = Test-Path $backupDir
    if ($backupDirExists) {
        try {
            $testFile = Join-Path $backupDir ('perm_test_' + [Guid]::NewGuid().ToString() + '.tmp')
            Set-Content -Path $testFile -Value 'ok'
            Remove-Item -Path $testFile -Force
            $backupDirWritable = $true
        } catch {
            $backupDirWritable = $false
        }
    }
}

$dbHost = $envConfig.host
$user = $envConfig.user
$password = $envConfig.password
$dbPath = if ($envKey -eq 'prod') { $database.prodPath } else { $database.testPath }

if (-not $dbPath) {
    throw "Database path not configured for $Environment"
}

$hostReachable = $null
if ($dbHost) {
    try {
        $hostReachable = Test-Connection -ComputerName $dbHost -Count 1 -Quiet -ErrorAction Stop
    } catch {
        $hostReachable = $false
    }
}

$canBackup = $gbakPath -and $backupDirExists -and $backupDirWritable -and $dbPath -and $user -and $password

$status = [ordered]@{
    success          = $true
    databaseId       = $DatabaseId
    databaseName     = $database.name
    environment      = $envKey.ToUpperInvariant()
    host             = $dbHost
    databasePath     = $dbPath
    gbakPath         = $gbakPath
    gbakExists       = [bool]$gbakPath
    backupDir        = $backupDir
    backupDirExists  = $backupDirExists
    backupDirWritable = $backupDirWritable
    hostReachable    = $hostReachable
    canBackup        = $canBackup
    message          = if ($canBackup) { 'Ready to create backup' } else { 'Missing prerequisites for backup.' }
}

if ($CheckOnly) {
    $status | ConvertTo-Json -Depth 5 | Write-Output
    exit 0
}

if (-not $canBackup) {
    throw 'Missing prerequisites for backup. Run status check for details.'
}

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$envLabel = if ($envKey -eq 'prod') { 'Prod' } else { 'Test' }
$fileName = "{0}-{1}-{2}.fbk" -f $database.name, $envLabel, $timestamp
$backupFile = Join-Path $backupDir $fileName
$script:LogFile = Join-Path $backupDir ("IBBackup_{0}_{1}_{2}.log" -f $DatabaseId, $envLabel, $timestamp)

Write-Log "=== InterBase Backup $($database.name) [$envLabel] started $(Get-Date) ==="

$started = Get-Date

try {
    $args = @(
        '-b','-t',
        '-user', $user,
        '-password', $password,
        "$dbHost`:$dbPath",
        $backupFile
    )
    Write-Log "Running gbak backup to $backupFile"
    Run-Cmd $gbakPath @args

    if (-not (Test-Path $backupFile)) {
        throw "Backup file was not created: $backupFile"
    }

    $fileInfo = Get-Item -Path $backupFile
    $duration = (Get-Date) - $started

    $result = [ordered]@{
        success        = $true
        databaseId     = $DatabaseId
        databaseName   = $database.name
        environment    = $envLabel
        backupFile     = $backupFile
        backupFileName = $fileName
        backupSize     = $fileInfo.Length
        backupSizeMb   = [math]::Round($fileInfo.Length / 1MB, 2)
        logFile        = $script:LogFile
        duration       = [string][math]::Round($duration.TotalSeconds, 2) + ' seconds'
        message        = 'Backup completed successfully.'
    }

    Write-Log "=== SUCCESS $(Get-Date) ==="
    $result | ConvertTo-Json -Depth 5 | Write-Output
} catch {
    Write-Log "ERROR: $_"
    throw
}
