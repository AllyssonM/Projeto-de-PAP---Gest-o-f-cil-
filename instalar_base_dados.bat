@echo off
setlocal EnableExtensions
 title Lumina - Instalar base de dados

set "XAMPP_DIR=C:\xampp"
set "MYSQL_EXE=%XAMPP_DIR%\mysql\bin\mysql.exe"
set "SQL_FILE=%~dp0database\gestao_facil.sql"

if not exist "%MYSQL_EXE%" (
    echo ERRO: mysql.exe nao foi encontrado em:
    echo %MYSQL_EXE%
    echo.
    echo Altere XAMPP_DIR neste ficheiro se o XAMPP estiver noutra pasta.
    pause
    exit /b 1
)

if not exist "%SQL_FILE%" (
    echo ERRO: o ficheiro database\gestao_facil.sql nao foi encontrado.
    pause
    exit /b 1
)

echo A criar/importar a base de dados gestao_facil...
"%MYSQL_EXE%" -u root -e "CREATE DATABASE IF NOT EXISTS gestao_facil CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo.
    echo Nao foi possivel ligar ao MySQL.
    echo Confirme se o MySQL esta ligado no XAMPP.
    echo Se o root tiver palavra-passe, importe database\gestao_facil.sql pelo phpMyAdmin.
    pause
    exit /b 1
)

"%MYSQL_EXE%" -u root gestao_facil < "%SQL_FILE%"
if errorlevel 1 (
    echo.
    echo A base foi criada, mas a importacao falhou.
    echo Tente importar o ficheiro pelo phpMyAdmin.
    pause
    exit /b 1
)

rem migracoes: seguras de repetir, nao apagam dados
for %%F in ("%~dp0database\migracao_v*.sql") do (
    echo A aplicar %%~nxF...
    "%MYSQL_EXE%" -u root --default-character-set=utf8mb4 < "%%F"
    if errorlevel 1 (
        echo.
        echo A migracao %%~nxF falhou. Tente importa-la pelo phpMyAdmin.
        pause
        exit /b 1
    )
)

echo.
echo Base de dados instalada com sucesso!
echo Agora abra: http://localhost/lumina/
echo.
if /i "%~1"=="auto" exit /b 0
pause
exit /b 0
