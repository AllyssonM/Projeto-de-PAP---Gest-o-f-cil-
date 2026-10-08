#!/usr/bin/env bash
# =========================================================================
# TESTES DO LUMINA NUM AMBIENTE LIMPO  (tests/ci/executar.sh)
# -------------------------------------------------------------------------
# Usado pelo GitHub Actions (.github/workflows/ci.yml). Também serve para repetir o CI na sua máquina, contra um MariaDB VAZIO.
#
# O que faz:
#   1. instala a base de dados como o instalar_base_dados.bat (database/gestao_facil.sql + migrações, pela mesma ordem);
#   2. cria o utilizador lumina_app (só SELECT, INSERT, UPDATE, DELETE) e corre tudo com ele, como em produção;
#   3. arranca o servidor PHP e espera que o /health diga "ok";
#   4. corre php tests/run.php e o detetor de segredos (tests/secret_scan.php).
#
# SEGURANÇA (o script cria e escreve numa base de dados, por isso recusa-se a correr onde pode fazer estragos):
#   - só corre com CI=true (o GitHub Actions define-o; na sua máquina tem de o escrever de propósito);
#   - só fala com um servidor de base de dados local (127.0.0.1, localhost ou ::1);
#   - recusa-se se a base gestao_facil já tiver tabelas. Nunca apaga nada.
#   - a palavra-passe de administração vem de LUMINA_DB_ADMIN_PASS (no CI é gerada na hora; nunca fica escrita no repositório)
#     e nunca é mostrada no ecrã nem passada na linha de comandos.
#
# Variáveis opcionais: LUMINA_DB_ADMIN_HOST (127.0.0.1) LUMINA_DB_ADMIN_PORT (3306) LUMINA_DB_ADMIN_USER (root) LUMINA_CI_PORT (8086)
# =========================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

falhar() { echo "ERRO: $*" >&2; exit 1; }

[ "${CI:-}" = "true" ] || falhar "só corre com CI=true: este script instala uma base de dados de testes."
HOST="${LUMINA_DB_ADMIN_HOST:-127.0.0.1}"
PORT="${LUMINA_DB_ADMIN_PORT:-3306}"
ADMIN="${LUMINA_DB_ADMIN_USER:-root}"
case "$HOST" in 127.0.0.1|localhost|::1) ;; *) falhar "o servidor de base de dados tem de ser local (recebi '$HOST')." ;; esac
export LUMINA_DB_ADMIN_HOST="$HOST" LUMINA_DB_ADMIN_PORT="$PORT" LUMINA_DB_ADMIN_USER="$ADMIN"
export MYSQL_PWD="${LUMINA_DB_ADMIN_PASS:-}"            # o cliente MySQL lê a palavra-passe daqui (não aparece em "ps")
CLIENT="$(command -v mariadb || command -v mysql || true)"
[ -n "$CLIENT" ] || falhar "falta o cliente mariadb/mysql."
sql() { "$CLIENT" --protocol=tcp -h "$HOST" -P "$PORT" -u "$ADMIN" --default-character-set=utf8mb4 "$@"; }

echo "== 1/4 Base de dados =="
sql -e "SELECT 1" >/dev/null 2>&1 || falhar "não consegui ligar ao servidor de base de dados em $HOST:$PORT (utilizador $ADMIN)."
EXISTENTES="$(sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'gestao_facil'")"
[ "$EXISTENTES" = "0" ] || falhar "a base gestao_facil já tem $EXISTENTES tabelas: este script só corre numa base vazia."
sql < database/gestao_facil.sql
for ficheiro in $(printf '%s\n' database/migracao_v*.sql | LC_ALL=C sort); do
    echo "  $ficheiro"
    sql < "$ficheiro"
done
echo "  tabelas: $(sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'gestao_facil'"); migrações registadas: $(sql -N -e 'SELECT COUNT(*) FROM gestao_facil.schema_migrations')"

echo "== 2/4 Utilizador da aplicação (menor privilégio) =="
[ ! -e config/database.local.php ] || falhar "config/database.local.php já existe."
php bin/criar_utilizador_bd.php

echo "== 3/4 Servidor PHP =="
APP_PORT="${LUMINA_CI_PORT:-8086}"
LOG="$(mktemp)"
php -S "127.0.0.1:$APP_PORT" -t . router.php >"$LOG" 2>&1 &
SERVIDOR=$!
trap 'kill "$SERVIDOR" 2>/dev/null || true; rm -f "$LOG"' EXIT
for _ in $(seq 1 100); do
    curl -fsS "http://127.0.0.1:$APP_PORT/health" >/dev/null 2>&1 && break
    sleep 0.2
done
curl -fsS "http://127.0.0.1:$APP_PORT/health" || { echo; echo "O /health não ficou ok. Registo do servidor:"; tail -n 40 "$LOG"; exit 1; }
echo

echo "== 4/4 Testes =="
RC=0
SAIDA="$(mktemp)"
trap 'kill "$SERVIDOR" 2>/dev/null || true; rm -f "$LOG" "$SAIDA"' EXIT
LUMINA_URL="http://127.0.0.1:$APP_PORT/" php tests/run.php 2>&1 | tee "$SAIDA" || RC=$?
php tests/secret_scan.php || RC=$?

# No GitHub, mostra o resultado em destaque (anotação + resumo do trabalho), sem ser preciso abrir o registo completo.
RESUMO="$(grep -E '^Passaram: ' "$SAIDA" | tail -n 1 || true)"
if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
    echo "::notice title=Testes do Lumina::${RESUMO:-sem resumo (a suite não chegou ao fim)}"
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        { echo "### Testes do Lumina"; echo; echo "${RESUMO:-sem resumo (a suite não chegou ao fim)}"; } >> "$GITHUB_STEP_SUMMARY"
    fi
    grep -E '^  FALHOU' "$SAIDA" | head -n 10 | cut -c1-300 | while IFS= read -r linha; do echo "::error title=Teste falhado::$linha"; done
fi
if [ "$RC" -ne 0 ]; then
    echo; echo "Falhou. Registo de erros do servidor PHP (últimas linhas):"; tail -n 40 "$LOG" || true
fi
exit "$RC"
