@echo off
REM ================================================================
REM  Lottery Scraper RD — Configurar Tarea Programada Windows
REM  Ejecuta el cron cada 1 minuto usando el Task Scheduler
REM ================================================================
REM  INSTRUCCIONES:
REM  1. Asegurate de que XAMPP esté instalado
REM  2. Ejecuta este .bat como ADMINISTRADOR
REM  3. La tarea se llamará "LotteryScraperRD"
REM ================================================================

SET PHP_EXE=C:\xampp\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\resultados\cronjob.php
SET TASK_NAME=LotteryScraperRD

echo Configurando tarea programada: %TASK_NAME%
echo PHP: %PHP_EXE%
echo Script: %SCRIPT_PATH%
echo.

REM Eliminar tarea existente (si hay)
schtasks /delete /tn "%TASK_NAME%" /f 2>nul

REM Crear nueva tarea cada 1 minuto
schtasks /create ^
  /tn "%TASK_NAME%" ^
  /tr "\"%PHP_EXE%\" \"%SCRIPT_PATH%\"" ^
  /sc MINUTE ^
  /mo 1 ^
  /f ^
  /ru SYSTEM ^
  /rl HIGHEST ^
  /st 00:00

IF %ERRORLEVEL% EQU 0 (
    echo.
    echo [OK] Tarea programada creada exitosamente!
    echo La extraccion comenzara en el proximo minuto.
    echo.
    echo Para verificar: schtasks /query /tn %TASK_NAME%
    echo Para detener:   schtasks /delete /tn %TASK_NAME% /f
) ELSE (
    echo.
    echo [ERROR] No se pudo crear la tarea. Ejecuta este script como Administrador.
)

pause
