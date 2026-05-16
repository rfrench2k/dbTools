function Get-Config {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        throw "Config file not found: $Path"
    }
    $raw = Get-Content -Raw -Path $Path
    if (-not $raw) {
        throw "Config file empty: $Path"
    }
    $cfg = $raw | ConvertFrom-Json
    if (-not $cfg) {
        throw "Unable to parse JSON config: $Path"
    }
    return $cfg
}

function Resolve-Tool {
    param(
        [string]$ConfiguredPath,
        [string]$ToolName
    )
    if ($ConfiguredPath -and (Test-Path $ConfiguredPath)) {
        return (Resolve-Path $ConfiguredPath).Path
    }
    $cmd = Get-Command $ToolName -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }
    return $null
}

function Run-Cmd {
    param(
        [string]$Exe,
        [Parameter(ValueFromRemainingArguments=$true)]
        [string[]]$Arguments
    )
    if (-not $Exe) {
        throw 'Executable path not specified.'
    }
    if (-not $Arguments -or ($Arguments | Where-Object { $_ -eq $null -or $_ -eq '' })) {
        throw "Argument list for $Exe contains null or empty values."
    }
    $cmdLine = "$Exe " + ($Arguments -join ' ')
    Write-Log ">> $cmdLine"
    $proc = Start-Process -FilePath $Exe -ArgumentList $Arguments -Wait -PassThru -NoNewWindow
    if ($proc.ExitCode -ne 0) {
        throw "Command failed ($($proc.ExitCode)): $cmdLine"
    }
}

function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $line = "[$timestamp] $Message"
    if ($script:LogFile) {
        Add-Content -Path $script:LogFile -Value $line
    }
}
