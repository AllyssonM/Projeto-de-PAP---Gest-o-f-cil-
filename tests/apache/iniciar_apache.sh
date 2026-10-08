#!/usr/bin/env bash
# =========================================================================
# APACHE PRIVADO PARA TESTAR O .htaccess  (tests/apache/iniciar_apache.sh)
# -------------------------------------------------------------------------
# O servidor embutido do PHP ignora o .htaccess. Este script arranca um Apache SÓ para os testes, com a sua própria configuração
# (não toca na instalação do sistema) e duas instalações do Lumina, como o tests/apache/check_htaccess.py espera:
#     http://127.0.0.1:8090/lumina   o projeto num subdiretório (como no XAMPP: htdocs/lumina)
#     http://127.0.0.1:8091          o projeto na raiz de um site
# Precisa de:  apache2  e  libapache2-mod-php8.3  (Ubuntu/Debian:  sudo apt-get install apache2 libapache2-mod-php8.3 php8.3-mysql php8.3-mbstring php8.3-gd php8.3-curl php8.3-xml)
# Uso:   bash tests/apache/iniciar_apache.sh start | stop | status
# Variáveis: LUMINA_DIR (pasta do projeto, por omissão a deste repositório)  LUMINA_APACHE_USER (utilizador do Apache; tem de poder escrever
#            em storage/ e config/; por omissão o utilizador atual, ou www-data se for root)  LUMINA_APACHE_RUN (pasta de trabalho)
#            LUMINA_APACHE_SUB_PORT (8090)  LUMINA_APACHE_ROOT_PORT (8091)  LUMINA_APACHE_ENV  (linhas "SetEnv NOME valor" extra, ex.: limites de teste)
# Não usa palavras-passe nem segredos.
# =========================================================================
set -euo pipefail

PROJ="$(cd "${LUMINA_DIR:-$(dirname "${BASH_SOURCE[0]}")/../..}" && pwd)"
RUN="${LUMINA_APACHE_RUN:-/tmp/lumina-apache-$(id -u)}"
SUB_PORT="${LUMINA_APACHE_SUB_PORT:-8090}"
ROOT_PORT="${LUMINA_APACHE_ROOT_PORT:-8091}"
if [ "$(id -u)" = "0" ]; then USER_AP="${LUMINA_APACHE_USER:-www-data}"; else USER_AP="${LUMINA_APACHE_USER:-$(id -un)}"; fi
GROUP_AP="$(id -gn "$USER_AP")"
MODS=/usr/lib/apache2/modules
PHP_MOD="$(find "$MODS" -maxdepth 1 -name 'libphp*.so' 2>/dev/null | head -n 1 || true)"
CONF="$RUN/apache2.conf"
PIDFILE="$RUN/apache.pid"

falhar() { echo "ERRO: $*" >&2; exit 1; }

escrever_conf() {
    mkdir -p "$RUN/sessions" "$RUN/tmp"
    chown "$USER_AP":"$GROUP_AP" "$RUN/sessions" "$RUN/tmp" 2>/dev/null || true
    chmod 700 "$RUN/sessions"
    local mods=""
    for m in authz_core mime dir alias rewrite headers expires deflate filter env setenvif; do
        [ -f "$MODS/mod_$m.so" ] || falhar "falta o módulo do Apache mod_$m."
        mods="$mods
LoadModule ${m}_module $MODS/mod_$m.so"
    done
    cat > "$CONF" <<EOF
ServerRoot "$RUN"
ServerName 127.0.0.1
PidFile "$PIDFILE"
Listen 127.0.0.1:$SUB_PORT
Listen 127.0.0.1:$ROOT_PORT
User $USER_AP
Group $GROUP_AP
ErrorLog "$RUN/error.log"
LogLevel warn
LoadModule mpm_prefork_module $MODS/mod_mpm_prefork.so$mods
LoadModule php_module $PHP_MOD
TypesConfig /etc/mime.types
DirectoryIndex index.php index.html
ServerTokens Prod
ServerSignature Off
StartServers 3
MinSpareServers 2
MaxSpareServers 4
MaxRequestWorkers 12
<FilesMatch "\.php\$">
    SetHandler application/x-httpd-php
</FilesMatch>
php_admin_value session.save_path "$RUN/sessions"
php_admin_value upload_tmp_dir "$RUN/tmp"
php_admin_flag log_errors on
# Como no php.ini do XAMPP: o PHP anuncia a sua versão (X-Powered-By). É o .htaccess que tem de a esconder, e o teste verifica-o.
php_admin_flag expose_php on
php_admin_value error_log "$RUN/php-error.log"
${LUMINA_APACHE_ENV:-}
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<VirtualHost 127.0.0.1:$SUB_PORT>
    Alias /lumina "$PROJ"
    <Directory "$PROJ">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
<VirtualHost 127.0.0.1:$ROOT_PORT>
    DocumentRoot "$PROJ"
    <Directory "$PROJ">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF
}

case "${1:-}" in
    start)
        command -v apache2 >/dev/null || falhar "o Apache (apache2) não está instalado."
        [ -n "$PHP_MOD" ] || falhar "falta o módulo PHP do Apache (libapache2-mod-php)."
        if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then echo "O Apache de teste já está a correr (pid $(cat "$PIDFILE"))."; exit 0; fi
        escrever_conf
        apache2 -f "$CONF" -t >/dev/null 2>"$RUN/configtest.log" || { cat "$RUN/configtest.log" >&2; falhar "a configuração do Apache de teste é inválida."; }
        apache2 -f "$CONF" -k start 2>"$RUN/start.log" || { cat "$RUN/start.log" "$RUN/error.log" >&2 2>/dev/null; falhar "o Apache não arrancou."; }
        for _ in $(seq 1 50); do
            curl -s -o /dev/null "http://127.0.0.1:$ROOT_PORT/robots.txt" && break
            sleep 0.2
        done
        curl -s -o /dev/null "http://127.0.0.1:$ROOT_PORT/robots.txt" || { tail -n 20 "$RUN/error.log" >&2; falhar "o Apache não responde."; }
        echo "Apache de teste a correr: http://127.0.0.1:$SUB_PORT/lumina  e  http://127.0.0.1:$ROOT_PORT  (utilizador $USER_AP; registos em $RUN)"
        ;;
    stop)
        if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then apache2 -f "$CONF" -k stop || true; echo "Apache de teste parado."; else echo "O Apache de teste não está a correr."; fi
        ;;
    status)
        if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then echo "a correr (pid $(cat "$PIDFILE"))"; else echo "parado"; exit 1; fi
        ;;
    *)
        falhar "uso: bash tests/apache/iniciar_apache.sh start|stop|status"
        ;;
esac
