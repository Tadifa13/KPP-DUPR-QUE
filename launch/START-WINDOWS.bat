@echo off
REM ===========================================================================
REM  KAMRYNNE QUE - double-click to run.
REM  Starts the app on this PC and opens it in your browser. No internet needed.
REM ===========================================================================
setlocal
title KAMRYNNE QUE
cd /d "%~dp0.."

echo.
echo   KAMRYNNE QUE
echo   ------------------------------------------------
echo.

where php >nul 2>nul
if errorlevel 1 (
  echo   PHP is not installed, or not on your PATH.
  echo.
  echo   Download it from https://windows.php.net/download
  echo   unzip it, then add that folder to PATH.
  echo   In php.ini, remove the ; in front of:  extension=pdo_sqlite
  echo.
  pause
  exit /b 1
)

php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if errorlevel 1 (
  echo   PHP is installed but the pdo_sqlite extension is off.
  echo   Open php.ini and remove the ; in front of:  extension=pdo_sqlite
  echo.
  pause
  exit /b 1
)

if not exist "data" mkdir "data"

REM Show the LAN address so phones on the same Wi-Fi can join.
echo   On this PC      http://localhost:8080
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4 Address"') do (
  for /f "tokens=* delims= " %%b in ("%%a") do echo   Other devices   http://%%b:8080
)
echo.
echo   Closing this window stops the app.
echo.

start "" http://localhost:8080
set PHP_CLI_SERVER_WORKERS=6
php -S 0.0.0.0:8080 -t .

pause
