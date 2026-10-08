@echo off
setlocal EnableExtensions
 title Lumina - Copia de seguranca da base de dados

rem =====================================================================================================
rem  COPIA DE SEGURANCA DIARIA. Duplo clique para fazer uma agora; o Agendador de Tarefas chama-o com "auto".
rem  EDITE SO AS DUAS LINHAS SEGUINTES.
rem  A pasta de destino tem de ficar FORA do site (nunca dentro de htdocs) e, de preferencia, noutro disco ou numa pen/nuvem.
rem  A frase-passe de cifra e o utilizador de copias NAO se escrevem aqui: ficam em config\backup.local.php (ver README).
rem =====================================================================================================
set "XAMPP_DIR=C:\xampp"
set "DESTINO=C:\lumina-backups"

set "PHP_EXE=%XAMPP_DIR%\php\php.exe"
set "AUTO=0"
if /i "%~1"=="auto" (
    set "AUTO=1"
    shift
)

if not exist "%PHP_EXE%" (
    echo ERRO: php.exe nao foi encontrado em: %PHP_EXE%
    echo Altere XAMPP_DIR neste ficheiro se o XAMPP estiver noutra pasta.
    if "%AUTO%"=="0" pause
    exit /b 1
)

"%PHP_EXE%" "%~dp0bin\backup_bd.php" "--destino=%DESTINO%" %1 %2 %3 %4 %5
set "RC=%ERRORLEVEL%"

if "%AUTO%"=="1" exit /b %RC%
echo.
if not "%RC%"=="0" echo A COPIA FALHOU. Leia a mensagem acima.
pause
exit /b %RC%
