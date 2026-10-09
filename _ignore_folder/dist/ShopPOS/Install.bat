@echo off
rem One-time setup on this computer:
rem  - installs the Microsoft runtime PHP needs (if missing)
rem  - adds "Shop POS" shortcuts on the Desktop and to start with Windows
rem  - lets phones on the shop Wi-Fi reach it (firewall rule, asks for permission)
cd /d "%~dp0"

if not exist "%SystemRoot%\System32\vcruntime140.dll" (
  echo Installing Microsoft Visual C++ runtime...
  "%~dp0vc_redist.x64.exe" /install /quiet /norestart
)

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
 "$s=New-Object -ComObject WScript.Shell;" ^
 "$t='%~dp0Start POS.bat';" ^
 "foreach($p in @([Environment]::GetFolderPath('Desktop')+'\Shop POS.lnk',[Environment]::GetFolderPath('Startup')+'\Shop POS.lnk')){$l=$s.CreateShortcut($p);$l.TargetPath=$t;$l.WorkingDirectory='%~dp0';$l.IconLocation='%~dp0pos.ico';$l.WindowStyle=7;$l.Save()}"

echo Allowing phones on the shop Wi-Fi to connect (Windows may ask for permission)...
powershell -NoProfile -Command "Start-Process netsh -Verb RunAs -ArgumentList 'advfirewall firewall add rule name=\"Shop POS\" dir=in action=allow protocol=TCP localport=8080 profile=private,domain' -WindowStyle Hidden" 2>nul

echo.
echo Done. Starting the POS...
call "%~dp0Start POS.bat"
