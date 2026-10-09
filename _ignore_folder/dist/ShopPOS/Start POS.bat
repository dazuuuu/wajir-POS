@echo off
rem Starts the Shop POS on this computer and opens it in the browser.
rem Works with no internet. Phones/tablets on the shop Wi-Fi can open
rem http://THIS-PC-IP:8080 (shown below).
cd /d "%~dp0"
if not exist "data\sessions" mkdir "data\sessions"
if not exist "data\logs" mkdir "data\logs"
if not exist "data\backups" mkdir "data\backups"

rem Already running? Just open it.
powershell -NoProfile -Command "try { (New-Object Net.Sockets.TcpClient).Connect('127.0.0.1', 8080); exit 0 } catch { exit 1 }"
if %errorlevel%==0 goto open

start "Shop POS server" /min /d "%~dp0php" "%~dp0php\php.exe" -c "%~dp0php\php.ini" -S 0.0.0.0:8080 -t "%~dp0public"
rem give it a moment to start
powershell -NoProfile -Command "Start-Sleep -Milliseconds 1200"

:open
rem (POS_NO_BROWSER is set by the automatic Windows test)
if "%POS_NO_BROWSER%"=="" start "" "http://localhost:8080/"
echo.
echo  Shop POS is running.  On this computer: http://localhost:8080/
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do echo  On phones/tablets on the shop Wi-Fi:   http:%%a:8080/
echo.
echo  You can close this window. Use "Stop POS" to shut it down.
powershell -NoProfile -Command "Start-Sleep -Seconds 6"
