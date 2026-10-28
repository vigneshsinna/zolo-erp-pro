@echo off
setlocal
cd /d "%~dp0"

rem Local dev stack: Laravel Herd PHP + portable MySQL 8.4 in C:\Users\vigne\zolo-mysql-dev (see its README.txt)
set MYSQL_DIR=C:\Users\vigne\zolo-mysql-dev
set PHP=%USERPROFILE%\.config\herd\bin\php85\php.exe
set DB_PORT=3306
set APP_PORT=8080

echo [1/3] Starting MySQL on 127.0.0.1:%DB_PORT%...
powershell -NoProfile -Command "try{(New-Object Net.Sockets.TcpClient('127.0.0.1',%DB_PORT%)).Close();exit 0}catch{exit 1}"
if %errorlevel%==0 goto dbup
start "MySQL-DEV (close to stop DB)" /min "%MYSQL_DIR%\start.cmd"

echo [2/3] Waiting for DB on 127.0.0.1:%DB_PORT%...
set /a tries=0
:waitdb
powershell -NoProfile -Command "try{(New-Object Net.Sockets.TcpClient('127.0.0.1',%DB_PORT%)).Close();exit 0}catch{exit 1}"
if %errorlevel%==0 goto dbup
set /a tries+=1
if %tries% GEQ 60 (
  echo DB not reachable after 60s. Abort.
  pause
  exit /b 1
)
timeout /t 1 /nobreak >nul
goto waitdb

:dbup
echo DB up.
echo [3/3] Starting ERP at http://localhost:%APP_PORT% (Ctrl+C to stop)...
start "" "http://localhost:%APP_PORT%"
rem opcache: without it every request recompiles the whole framework (~1 s per request on the built-in server)
rem Check source timestamps every 2 seconds; checking every file on every request is costly on Windows.
"%PHP%" -d opcache.enable_cli=1 -d opcache.memory_consumption=256 -d opcache.max_accelerated_files=50000 -d opcache.revalidate_freq=2 -S 0.0.0.0:%APP_PORT% -t public server.php
endlocal
