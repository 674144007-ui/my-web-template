@echo off
echo ========================================
echo  Smart School Plus - Auto Fix Script
echo ========================================
echo.

REM หา path จริงที่ MAMP ใช้
echo [1] ตรวจ paths ที่เป็นไปได้...

REM Path 1: C:\MAMP\htdocs
if exist "C:\MAMP\htdocs\my-web-template-main\" (
    echo   Found: C:\MAMP\htdocs\my-web-template-main\
    copy /Y "%~dp0lab_realistic.php" "C:\MAMP\htdocs\my-web-template-main\lab_realistic.php"
    copy /Y "%~dp0header.php" "C:\MAMP\htdocs\my-web-template-main\header.php"
    copy /Y "%~dp0sw.js" "C:\MAMP\htdocs\my-web-template-main\sw.js"
    copy /Y "%~dp0api_auto_grade.php" "C:\MAMP\htdocs\my-web-template-main\api_auto_grade.php"
    copy /Y "%~dp0api_heartbeat.php" "C:\MAMP\htdocs\my-web-template-main\api_heartbeat.php"
    copy /Y "%~dp0teacher_review_lab.php" "C:\MAMP\htdocs\my-web-template-main\teacher_review_lab.php"
    copy /Y "%~dp0generate_lab_report.php" "C:\MAMP\htdocs\my-web-template-main\generate_lab_report.php"
    copy /Y "%~dp0teacher_broadcast.php" "C:\MAMP\htdocs\my-web-template-main\teacher_broadcast.php"
    copy /Y "%~dp0lab_analytics.php" "C:\MAMP\htdocs\my-web-template-main\lab_analytics.php"
    echo   [OK] Copied to C:\MAMP\htdocs\my-web-template-main\
)

REM Path 2: C:\Users\Public\Documents\MAMP\htdocs  
if exist "C:\Users\Public\Documents\MAMP\htdocs\my-web-template-main\" (
    echo   Found: C:\Users\Public\Documents\MAMP\htdocs\my-web-template-main\
    copy /Y "%~dp0lab_realistic.php" "C:\Users\Public\Documents\MAMP\htdocs\my-web-template-main\lab_realistic.php"
    echo   [OK] Copied
)

REM Path 3: หา MAMP config เพื่อดู Document Root จริง
echo.
echo [2] Document Root จาก MAMP config:
type "C:\MAMP\conf\apache\httpd.conf" 2>nul | findstr "DocumentRoot"
type "C:\MAMP\bin\apache\conf\httpd.conf" 2>nul | findstr "DocumentRoot"

echo.
echo [3] ตรวจ lab_realistic.php ทุก path:
for /f "delims=" %%i in ('where /r C:\ lab_realistic.php 2^>nul') do (
    echo   Path: %%i
    find /c /v "" "%%i" 2>nul
)

echo.
echo ========================================
echo  เสร็จแล้ว! กด Ctrl+Shift+R ใน browser
echo ========================================
pause
