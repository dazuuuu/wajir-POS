@echo off
rem Stops the Shop POS server (your data is kept).
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":8080" ^| findstr "LISTENING"') do taskkill /PID %%p /F >nul 2>&1
echo Shop POS stopped.
powershell -NoProfile -Command "Start-Sleep -Seconds 2"
