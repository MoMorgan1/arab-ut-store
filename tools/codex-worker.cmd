@echo off
powershell.exe -NoProfile -File "%~dp0codex-worker.ps1" %*
exit /b %ERRORLEVEL%
