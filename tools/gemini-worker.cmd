@echo off
powershell.exe -NoProfile -File "%~dp0gemini-worker.ps1" %*
exit /b %ERRORLEVEL%
