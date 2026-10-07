@echo off
setlocal EnableExtensions
 title Lumina - Arranque automático

REM ============================================================
REM Lumina - arranque automático com XAMPP
REM Se o XAMPP estiver noutra pasta, altere XAMPP_DIR.
REM ============================================================
set "XAMPP_DIR=C:\xampp"
set "PROJECT_URL=http://localhost/lumina/"

if not exist "%XAMPP_DIR%\xampp-control.exe" (
    echo.
    echo ERRO: O XAMPP nao foi encontrado em:
    echo %XAMPP_DIR%
    echo.
    echo Edite este ficheiro e altere a linha XAMPP_DIR para o local correto.
    echo Exemplo: set "XAMPP_DIR=D:\xampp"
    pause
    exit /b 1
)

if not exist "%XAMPP_DIR%\apache_start.bat" (
    echo ERRO: apache_start.bat nao foi encontrado na pasta do XAMPP.
    pause
    exit /b 1
)

if not exist "%XAMPP_DIR%\mysql_start.bat" (
    echo ERRO: mysql_start.bat nao foi encontrado na pasta do XAMPP.
    pause
    exit /b 1
)

echo A iniciar o Apache do XAMPP...
start "Lumina - Apache" /min "%ComSpec%" /c ""%XAMPP_DIR%\apache_start.bat""

echo A iniciar o MySQL do XAMPP...
start "Lumina - MySQL" /min "%ComSpec%" /c ""%XAMPP_DIR%\mysql_start.bat""

echo A aguardar os servicos...
timeout /t 5 /nobreak >nul

echo A verificar a base de dados...
call "%~dp0instalar_base_dados.bat" auto
if errorlevel 1 (
    echo A aplicacao nao foi aberta porque a base de dados nao foi instalada.
    pause
    exit /b 1
)

echo A abrir o Lumina...
start "" "%PROJECT_URL%"

echo.
echo O Lumina foi aberto em:
echo %PROJECT_URL%
echo.
echo Pode fechar esta janela. Apache e MySQL continuam ativos no XAMPP.
exit /b 0
