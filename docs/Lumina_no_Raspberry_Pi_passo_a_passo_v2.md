# Lumina no Raspberry Pi 4: passo a passo (v2)

**Estado honesto:** escrito a partir do código e dos testes em Linux (Debian, Apache 2.4, PHP 8.3, MariaDB 10.11). **Não foi testado num Raspberry Pi real.** Os comandos são os habituais do Raspberry Pi OS (Debian), mas confirma a versão do PHP que o `apt` instala (`php -v`): o Lumina foi testado com 8.3.
Esta versão substitui o guia antigo, que usava o utilizador `root` da base de dados e deixava palavras-passe em linhas de comando. O guia antigo fica como está, por ordem tua.

**Fora do âmbito (precisa de decisão tua):** domínio, DNS, HTTPS e abrir portas no router. Este guia deixa o Lumina a funcionar **na tua rede local** (`http://IP-DO-PI/`). Não o expões à internet sem HTTPS.

## 0. O que precisas
Raspberry Pi 4 (2 GB ou mais), cartão de 16 GB ou mais (melhor um SSD USB), Raspberry Pi OS **Lite 64 bits**, ligação à rede. Acesso por SSH com o teu utilizador (abaixo, `pi`; usa o teu).

## 1. Sistema e pacotes
```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y apache2 mariadb-server git unzip \
  php php-cli libapache2-mod-php php-mysql php-mbstring php-xml php-curl php-gd php-zip
sudo a2enmod rewrite headers
php -v        # confirma 8.x; o libsodium já vem no PHP 8
```

## 2. O código em `/var/www/html`
```bash
sudo rm -f /var/www/html/index.html
sudo git clone https://github.com/AllyssonM/Projeto-de-PAP---Gest-o-f-cil-.git /var/www/html
cd /var/www/html && sudo git checkout main     # ou a branch que queres testar
sudo chown -R www-data:www-data storage config
sudo chmod -R u=rwX,g=rX,o= storage config
```
Se a pasta do site for outra (ex.: `/var/www/html/lumina`), ajusta os caminhos abaixo.

## 3. Apache: deixar o `.htaccess` funcionar
```bash
sudo nano /etc/apache2/sites-available/000-default.conf
```
Dentro de `<VirtualHost *:80>` acrescenta:
```apache
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
ServerTokens Prod
ServerSignature Off
```
```bash
sudo apache2ctl configtest && sudo systemctl restart apache2
```
`AllowOverride All` é o que faz o `.htaccess` (endereços limpos, bloqueio de pastas internas, cabeçalhos de segurança) valer. Sem isto, as pastas internas **ficam acessíveis**: confirma no fim (passo 9).

## 4. Base de dados (sem usar o `root` na aplicação)
```bash
sudo mariadb-secure-installation        # responde às perguntas; define a palavra-passe tu, no ecrã
cd /var/www/html
sudo mariadb < database/gestao_facil.sql
for f in $(printf '%s\n' database/migracao_v*.sql | LC_ALL=C sort); do echo "== $f"; sudo mariadb < "$f"; done
sudo mariadb gestao_facil -e "SELECT version FROM schema_migrations ORDER BY version"
```
A lista tem de ir até à **v19**. Repetir é seguro e não apaga dados.

## 5. Utilizador da aplicação com poderes mínimos
No Raspberry Pi OS, o `root` do MariaDB entra por *socket*; o script do Lumina liga por rede. Cria um administrador **temporário**; a palavra-passe escreves tu no ecrã, **não fica em histórico nem em ficheiros**:
```bash
read -rsp "Palavra-passe do administrador temporário (inventa uma): " ADMPW; echo
sudo mariadb -e "CREATE USER 'tmp_admin'@'127.0.0.1' IDENTIFIED BY '$ADMPW'; GRANT ALL PRIVILEGES ON *.* TO 'tmp_admin'@'127.0.0.1' WITH GRANT OPTION;"
sudo -u www-data env LUMINA_DB_ADMIN_USER=tmp_admin LUMINA_DB_ADMIN_PASS="$ADMPW" php bin/criar_utilizador_bd.php                # utilizador da aplicação
sudo -u www-data env LUMINA_DB_ADMIN_USER=tmp_admin LUMINA_DB_ADMIN_PASS="$ADMPW" php bin/criar_utilizador_bd.php --para=backup  # utilizador das cópias (só lê)
sudo mariadb -e "DROP USER 'tmp_admin'@'127.0.0.1';"
unset ADMPW
```
O script grava as palavras-passe geradas em `config/database.local.php` e `config/backup.local.php` (permissão 0600, ignorados pelo Git) e **não as mostra**.
## 6. Primeiro arranque e verificação
Abre `http://IP-DO-PI/` e cria a tua conta. Depois:
```bash
curl -s http://localhost/health        # deve dizer "status":"ok"
sudo -u www-data php tests/run.php "Cabeçalhos" 2>&1 | tail -3   # opcional, não precisa de servidor
```

## 7. Cópias de segurança (sem palavra-passe nas linhas de comando)
```bash
sudo install -d -o www-data -g www-data -m 0750 /var/backups/lumina
sudo -u www-data nano /var/www/html/config/backup.local.php   # acrescenta a frase-passe de cifra (≥ 16 caracteres), ver comentários do ficheiro
sudo -u www-data php /var/www/html/bin/backup_bd.php --destino=/var/backups/lumina
```
Agendar todos os dias às 03:00, como `www-data` (sem segredos na linha):
```bash
sudo crontab -u www-data -e
# 0 3 * * * /usr/bin/php /var/www/html/bin/backup_bd.php --destino=/var/backups/lumina >> /var/www/html/storage/logs/backup-cron.log 2>&1
```
- **Guarda a frase-passe num sítio seguro e separado** do Pi: sem ela as cópias cifradas não abrem.
- Copia as cópias também para **fora** do Pi (outro disco ou computador). Um cartão SD morre sem aviso. Isto não está automatizado.
- Não há alerta automático se a cópia falhar: vê `storage/backup-status.json` e o `/health` ("backup" desatualizado).
- Os dados apagados pelos utilizadores continuam nas cópias até expirarem (≈ 6 meses por omissão): ver `docs/RGPD_INTEGRACOES.md`.

## 8. Alertas de estoque e e-mail
O e-mail vem em modo de teste (`log`: nada sai). Para enviar a sério configura o SMTP em `config/mail.local.php` ou com `LUMINA_SMTP_*` (palavra-passe só aí, nunca no GitHub). Depois:
```bash
sudo -u www-data php bin/alertas_estoque.php --ativar=o-teu@email     # só o dono, com e-mail confirmado
sudo -u www-data php bin/alertas_estoque.php --simular
# cron: 0 8 * * * /usr/bin/php /var/www/html/bin/alertas_estoque.php
```

## 9. Segurança: o que confirmar antes de usar a sério
```bash
curl -sI http://localhost/config/database.php | head -1     # tem de ser 403 ou 404, NUNCA 200
curl -sI http://localhost/database/gestao_facil.sql | head -1
curl -sI http://localhost/storage/backup-status.json | head -1
curl -sI http://localhost/ | grep -i "x-frame-options\|content-security\|x-powered-by"   # os dois primeiros devem aparecer; X-Powered-By não
```
- Firewall: `sudo apt install ufw && sudo ufw allow 22 && sudo ufw allow 80 && sudo ufw enable` (só na tua rede; sem abrir no router).
- Atualizações: `sudo apt update && sudo apt upgrade` regularmente; `git pull` e voltar a correr o passo 4 para novas migrações.
- Cartão SD: desgasta com escrita constante. Preferível SSD USB para a base de dados.
- Para expor à internet: precisas de domínio + HTTPS (ex.: proxy com certificado) e de definir `LUMINA_TRUSTED_PROXIES` se houver proxy. **Decisão tua**; ver `docs/SEGURANCA_CABECALHOS.md`.

## 10. Problemas comuns
| Sintoma | Causa provável |
|---|---|
| Página em branco / erro 500 | `sudo tail -n 30 /var/log/apache2/error.log`; falta uma extensão PHP (passo 1) |
| `/health` diz `degraded`/`fail` | falta uma migração (passo 4) ou a ligação à BD (`config/database.local.php`) |
| Endereços sem `.php` dão 404 | `a2enmod rewrite` e `AllowOverride All` (passos 1 e 3) |
| «Access denied» à base de dados | repete o passo 5; o utilizador `lumina_app` tem de existir |
| Não deixa guardar fotos/logo | `chown -R www-data:www-data storage` |
