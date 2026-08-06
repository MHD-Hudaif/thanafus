@echo off
:: This script stops and disables the MySQL80 service to allow Laragon's MySQL to run on port 3306.
:: It must be run as Administrator.

echo Checking administrative privileges...
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Running with Administrator privileges.
) else (
    echo WARNING: This script MUST be run as Administrator!
    echo Please right-click this file and select "Run as administrator".
    pause
    exit /b 1
)

echo Stopping MySQL80 service...
sc stop MySQL80
if %errorLevel% == 0 (
    echo Successfully stopped MySQL80 service.
) else (
    echo MySQL80 service is not running or could not be stopped.
)

echo Disabling MySQL80 service startup on boot...
sc config MySQL80 start= demand
if %errorLevel% == 0 (
    echo Successfully configured MySQL80 startup to Manual.
) else (
    echo Could not configure MySQL80 service.
)

echo.
echo ==============================================================
echo Done! Please open Laragon and start the MySQL service.
echo It will now successfully run on port 3306 with an empty password.
echo ==============================================================
pause
