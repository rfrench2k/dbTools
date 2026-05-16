# ----- USER SETTINGS ---------------------------------------------------------
# Paths to tools on the TEST server (adjust for your install/version)
$Gbak = "C:\Program Files\Embarcadero\InterBase\bin\gbak.exe"   # InterBase path is similar; update if needed
$Gfix = "C:\Program Files\Embarcadero\InterBase\bin\gfix.exe"

# Production connection (source)
$ProdHost = "your-prod-host"                         # e.g. "prod-db01"
$ProdDb   = "D:\path\to\YOURDB.IB"                   # full path on PROD
$ProdUser = "SYSDBA"                                 # or appropriate user
$ProdPass = "your_password"                          # secure this in practice

# Test connection (target) - this script runs on TEST
$TestHost = "localhost"                              # we run commands locally on TEST
$TestDb   = "D:\path\to\YOURDB.IB"                   # full path on TEST
$TestUser = "SYSDBA"
$TestPass = "your_password"

# Where to write the .fbk backup on TEST
$BackupDir = "D:\Dumps\Interbase"
# -----------------------------------------------------------------------------

# Create backup dir if missing
if (!(Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir | Out-Null }

$ts = Get-Date -Format "yyyyMMdd_HHmmss"
$BackupFile = Join-Path $BackupDir ("MYDB_fromPROD_{0}.fbk" -f $ts)
$LogFile    = Join-Path $BackupDir ("RefreshLog_{0}.log" -f $ts)

function Run-Cmd {
    param(
        [Parameter(Mandatory=$true)]
        [string]$exe,
        [Parameter(ValueFromRemainingArguments=$true)]
        [string[]]$args
    )
    if (-not $exe) { throw "Executable path not specified." }
    if (-not $args -or ($args | Where-Object { $_ -eq $null -or $_ -eq "" })) {
        throw "Argument list for $exe contains null or empty values."
    }
    Write-Host ">> $exe $($args -join ' ')"
    $p = Start-Process -FilePath $exe -ArgumentList $args -Wait -PassThru -NoNewWindow
    if ($p.ExitCode -ne 0) {
        throw "Command failed: $exe $($args -join ' ') (ExitCode=$($p.ExitCode))"
    }
}

try {
    "=== Prod?Test Refresh Started: $(Get-Date) ===" | Tee-Object -FilePath $LogFile

    # 1) BACKUP from PROD to .fbk on TEST (transportable backup)
    #    Firebird/InterBase: -b (backup) -t (transportable) -g (skip garbage collect is optional)
    $backupArgs = @(
        "-b",
        "-t",
        "-user", $ProdUser,
        "-password", $ProdPass,
        "$ProdHost`:$ProdDb",
        "$BackupFile"
    )
    "Step 1: Backing up PROD to: $BackupFile" | Tee-Object -FilePath $LogFile -Append
    Run-Cmd $Gbak @backupArgs

    # 2) SHUTDOWN TEST DB (forces all connections off)
    #    Firebird/InterBase: -shut -force 0 (immediate); you can use a grace period like -force 60 if preferred
    if (Test-Path $TestDb) {
        $shutArgs = @(
            "-shut",
            "-force", "0",
            "-user", $TestUser,
            "-password", $TestPass,
            "$TestHost`:$TestDb"
        )
        "Step 2: Shutting down TEST DB..." | Tee-Object -FilePath $LogFile -Append
        Run-Cmd $Gfix @shutArgs
    }

    # 3) RESTORE into TEST (replace existing database)
    #    Firebird/InterBase: -c (create/restore) -replace_database (overwrite target if exists)
    $restoreArgs = @(
        "-c",
        "-replace_database",
        "-user", $TestUser,
        "-password", $TestPass,
        "$BackupFile",
        "$TestHost`:$TestDb"
    )
    "Step 3: Restoring to TEST DB: $TestDb" | Tee-Object -FilePath $LogFile -Append
    Run-Cmd $Gbak @restoreArgs

    # 4) Bring TEST back online (+ optional sweep/validate)
    $onlineArgs = @(
        "-online",
        "-user", $TestUser,
        "-password", $TestPass,
        "$TestHost`:$TestDb"
    )
    "Step 4: Bringing TEST DB online..." | Tee-Object -FilePath $LogFile -Append
    Run-Cmd $Gfix @onlineArgs

    # (Optional) Quick health sweep after restore
    # $sweepArgs = @("-sweep","-user",$TestUser,"-password",$TestPass,"$TestHost`:$TestDb")
    # Run-Cmd $Gfix @sweepArgs

    "=== SUCCESS: Completed at $(Get-Date) ===" | Tee-Object -FilePath $LogFile -Append
    Write-Host "`n Prod?Test refresh complete."
    Write-Host "Log: $LogFile"
    Write-Host "Backup kept at: $BackupFile"
}
catch {
    "=== ERROR: $_ ===" | Tee-Object -FilePath $LogFile -Append
    Write-Error " Refresh failed. See log: $LogFile"
    exit 1
}
