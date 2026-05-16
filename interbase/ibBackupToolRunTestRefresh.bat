@echo off
setlocal
set "SCRIPT_DIR=%~dp0"
pushd "%SCRIPT_DIR%" >nul
set "PS=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
"%PS%" -NoProfile -ExecutionPolicy Bypass -File "ibBackupToolTestRefresh.ps1"
set "EXITCODE=%ERRORLEVEL%"
popd >nul
if not "%EXITCODE%"=="0" (
    echo ibBackupToolTestRefresh.ps1 failed with exit code %EXITCODE%.
) else (
    echo ibBackupToolTestRefresh.ps1 completed successfully.
)
pause
exit /b %EXITCODE%
