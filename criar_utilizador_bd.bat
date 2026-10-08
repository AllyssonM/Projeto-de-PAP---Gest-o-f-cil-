@echo off
setlocal EnableExtensions
 title Lumina - Criar utilizador da base de dados

rem Cria o utilizador MySQL da aplicacao (so SELECT, INSERT, UPDATE e DELETE) e guarda os dados em config\database.local.php.
rem Se o root do MySQL tiver palavra-passe, defina-a ANTES de executar:  set LUMINA_DB_ADMIN_PASS=a_sua_palavra_passe
set "XAMPP_DIR=C:\xampp"
set "PHP_EXE=%XAMPP_DIR%\php\php.exe"

if not exist "%PHP_EXE%" (
    echo ERRO: php.exe nao foi encontrado em:
    echo %PHP_EXE%
    echo.
    echo Altere XAMPP_DIR neste ficheiro se o XAMPP estiver noutra pasta.
    pause
    exit /b 1
)

"%PHP_EXE%" "%~dp0bin\criar_utilizador_bd.php" %*
if errorlevel 1 (
    echo.
    echo Nao foi possivel concluir. Leia a mensagem acima.
    pause
    exit /b 1
)

echo.
pause
exit /b 0
