#!/usr/bin/env bash
# CGA Connect — installation ou mise à jour de la recette sur un hébergement cPanel
# (infra/cpanel/README.md, décision 0011). Dans le Terminal de cPanel, depuis le dossier du paquet
# décompressé :
#     bash install.sh                  installe ou met à jour
#     bash install.sh --mot-de-passe   change d'abord le mot de passe de la base dans le .env
#
# Comme infra/scripts/deploy.sh, chaque version est construite à part dans releases/<date> et le lien
# current ne bascule qu'une fois tout prêt ; un échec avant la bascule laisse la version en service
# intacte. Différences imposées par le mutualisé : pas de Supervisor (un cron chaque minute lance les
# workers), pas de rechargement de PHP-FPM, pas de LibreOffice (aucun PDF), SPA déjà construit dans
# le paquet (pas de Node sur le serveur).
set -euo pipefail

PACKAGE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# Fichier éventuellement édité sous Windows : retours chariot retirés.
# shellcheck disable=SC1090
source <(tr -d '\r' < "$PACKAGE_DIR/cpanel.env")

PHP_BIN=${PHP_BIN:-$(command -v php || true)}
REVISION=$(cat "$PACKAGE_DIR/REVISION")
RELEASE=$(date +%Y%m%d%H%M%S)
RELEASE_DIR="$APP_DIR/releases/$RELEASE"
SHARED_DIR="$APP_DIR/shared"
ENV_FILE="$SHARED_DIR/.env"
SEEDED_MARK="$SHARED_DIR/.seeded"
DOCROOT_MARK="$DOCROOT/.cga-connect"
CRON_BEGIN="# >>> cga-connect (infra/cpanel/install.sh) : ne pas modifier entre ces lignes"
CRON_END="# <<< cga-connect"
SWITCHED=false
FIRST_INSTALL=false

mkdir -p "$APP_DIR/releases" "$SHARED_DIR"
chmod 700 "$SHARED_DIR"
LOG_FILE="$APP_DIR/deploy.log"

log() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" | tee -a "$LOG_FILE"
}

fail() {
    log "ÉCHEC : $*"
    exit 1
}

finish() {
    local code=$?
    if [[ $code -ne 0 && "$SWITCHED" == false && -d "$RELEASE_DIR" ]]; then
        rm -rf "$RELEASE_DIR"
        log "Version $RELEASE abandonnée (code $code) ; la version en service n'a pas changé."
    elif [[ $code -ne 0 ]]; then
        log "Erreur après la bascule (code $code) : voir « Retour arrière » dans infra/cpanel/README.md."
    fi
}
trap finish EXIT

artisan() {
    "$PHP_BIN" "$RELEASE_DIR/backend/artisan" "$@"
}

set_env() {
    "$PHP_BIN" "$PACKAGE_DIR/set-env.php" "$ENV_FILE" "$1" "$2"
}

ask_db_password() {
    local password
    read -rsp "Mot de passe de la base $DB_DATABASE (utilisateur $DB_USERNAME), invisible à la saisie : " password
    echo
    [[ -n "$password" ]] || fail "mot de passe vide"
    set_env DB_PASSWORD "$password" || fail "mot de passe refusé (ni apostrophe ni retour à la ligne)"
}

# Remplace, dans un fichier, le bloc délimité par deux lignes repères ; le reste du fichier (par
# exemple le gestionnaire PHP écrit par cPanel dans .htaccess) est gardé tel quel.
replace_block() {
    local file=$1 begin=$2 end=$3 content=$4
    {
        if [[ -f "$file" ]]; then
            awk -v b="$begin" -v e="$end" '$0 == b { skip = 1; next } $0 == e { skip = 0; next } !skip' "$file"
        fi
        printf '%s\n%s\n%s\n' "$begin" "$content" "$end"
    } > "$file.cga-new"
    mv -f "$file.cga-new" "$file"
}

exec 9> "$APP_DIR/.deploy.lock"
flock -n 9 || fail "une autre installation est en cours"

log "Installation de la version $REVISION ($DEPLOY_ENV) pour https://$DOMAIN"

# 1. Prérequis du serveur.
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || fail "php introuvable : renseigner PHP_BIN dans cpanel.env"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
    || fail "PHP 8.3 ou plus exigé ($PHP_BIN est en $("$PHP_BIN" -r 'echo PHP_VERSION;'))"
for extension in pdo_pgsql pgsql intl zip gd bcmath mbstring curl fileinfo sodium; do
    "$PHP_BIN" -r "exit(extension_loaded('$extension') ? 0 : 1);" || fail "extension PHP manquante : $extension"
done
command -v composer > /dev/null || fail "composer introuvable"
[[ -n "$DB_DATABASE" && -n "$DB_USERNAME" ]] || fail "DB_DATABASE et DB_USERNAME à renseigner dans cpanel.env"
[[ -d "$DOCROOT" ]] || fail "dossier public introuvable : $DOCROOT"

if [[ ! -f "$DOCROOT_MARK" ]]; then
    unexpected=$(find "$DOCROOT" -mindepth 1 -maxdepth 1 ! -name cgi-bin ! -name .well-known \
        ! -name .htaccess ! -name .user.ini -printf '%f ')
    [[ -z "$unexpected" ]] \
        || fail "le dossier public $DOCROOT contient déjà : $unexpected; le vider ou choisir un autre dossier"
    for existing in .htaccess .user.ini; do
        if [[ -f "$DOCROOT/$existing" ]]; then
            mkdir -p "$APP_DIR/avant-installation"
            cp -a "$DOCROOT/$existing" "$APP_DIR/avant-installation/"
        fi
    done
fi

# 2. Code de la version et fichiers partagés entre les versions (configuration, stockage, journaux).
log "Version $RELEASE : copie du code"
mkdir -p "$RELEASE_DIR"
cp -a "$PACKAGE_DIR/backend" "$RELEASE_DIR/backend"
cp -a "$PACKAGE_DIR/frontend-dist" "$RELEASE_DIR/frontend-dist"
echo "$REVISION" > "$RELEASE_DIR/REVISION"

mkdir -p "$SHARED_DIR/storage/app/private" "$SHARED_DIR/storage/framework/cache/data" \
    "$SHARED_DIR/storage/framework/sessions" "$SHARED_DIR/storage/framework/views" "$SHARED_DIR/storage/logs"
rm -rf "$RELEASE_DIR/backend/storage"
ln -s "$SHARED_DIR/storage" "$RELEASE_DIR/backend/storage"

# 3. Configuration de Laravel : créée à la première installation, gardée ensuite.
if [[ ! -f "$ENV_FILE" ]]; then
    FIRST_INSTALL=true
    log "Première installation : création de $ENV_FILE"
    install -m 600 "$RELEASE_DIR/backend/.env.example" "$ENV_FILE"
    set_env APP_ENV "$DEPLOY_ENV"
    set_env APP_DEBUG false
    set_env APP_URL "https://$DOMAIN"
    set_env LOG_CHANNEL daily
    set_env DB_CONNECTION pgsql
    set_env DB_HOST "$DB_HOST"
    set_env DB_PORT "$DB_PORT"
    set_env DB_DATABASE "$DB_DATABASE"
    set_env DB_USERNAME "$DB_USERNAME"
    set_env SESSION_SECURE_COOKIE true
    set_env SANCTUM_STATEFUL_DOMAINS ''
    set_env CORS_ALLOWED_ORIGINS ''
    set_env MAIL_MAILER log
    set_env LIBREOFFICE_BIN soffice
    set_env SENTRY_LARAVEL_DSN ''
    set_env SENTRY_ENVIRONMENT "$DEPLOY_ENV"
    set_env SEED_DEMO "$SEED_DEMO"
    # Mot de passe des comptes de démonstration propre au serveur (la valeur par défaut est publique).
    set_env SEED_DEMO_PASSWORD "$("$PHP_BIN" -r 'echo "Recette-", bin2hex(random_bytes(6));')"
    ask_db_password
elif [[ "${1:-}" == "--mot-de-passe" ]]; then
    ask_db_password
    log "Mot de passe de la base mis à jour dans $ENV_FILE"
fi
ln -s "$ENV_FILE" "$RELEASE_DIR/backend/.env"

# 4. Dépendances PHP, sans les outils de développement.
log "Dépendances PHP (composer install --no-dev)"
(cd "$RELEASE_DIR/backend" && COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --prefer-dist \
    --no-interaction --no-progress --optimize-autoloader)
grep -qE '^APP_KEY=.+' "$ENV_FILE" || artisan key:generate --force --no-interaction

# 5. Base : contrôle (sans rien modifier), migrations, données de base à la première installation.
log "Contrôle de la base $DB_DATABASE"
set +e
"$PHP_BIN" "$PACKAGE_DIR/db-check.php" "$RELEASE_DIR/backend" 2>&1 | tee -a "$LOG_FILE"
db_state=${PIPESTATUS[0]}
set -e
case $db_state in
    0) ;;
    10) fail "la base $DB_DATABASE sert déjà à une autre application : créer une base dédiée dans cPanel et l'indiquer dans cpanel.env" ;;
    11) fail "demander à l'hébergeur d'activer les extensions pg_trgm, unaccent et citext sur la base $DB_DATABASE" ;;
    12) fail "connexion à la base impossible : relancer « bash install.sh --mot-de-passe » et ressaisir le mot de passe" ;;
    *) fail "contrôle de la base en erreur (code $db_state)" ;;
esac

log "Migrations"
artisan migrate --force --no-interaction
if [[ ! -f "$SEEDED_MARK" ]]; then
    log "Données de base (et comptes de démonstration si SEED_DEMO=true)"
    artisan db:seed --force --no-interaction
    date '+%F %T' > "$SEEDED_MARK"
fi
artisan config:cache
artisan route:cache

# 6. Bascule atomique du lien current.
ln -sfn "$RELEASE_DIR" "$APP_DIR/current.next"
mv -Tf "$APP_DIR/current.next" "$APP_DIR/current"
SWITCHED=true
log "Version $RELEASE en service"

# 7. Dossier public : point d'entrée de l'API, règles Apache, réglages PHP, puis le SPA (index.html en dernier).
log "Publication dans $DOCROOT"
cat > "$DOCROOT/index.php" <<PHP
<?php

// CGA Connect : point d'entrée de l'API et de Sanctum, produit par infra/cpanel/install.sh.
require '$APP_DIR/current/backend/public/index.php';
PHP

HTACCESS=$(cat <<APACHE
# Produit par infra/cpanel/install.sh : modifier cpanel.env et relancer l’installation.
<IfModule mod_negotiation.c>
    Options -MultiViews -Indexes
</IfModule>
DirectoryIndex index.html

# Relais HTTPS de l’hébergeur : PHP voit la requête comme sécurisée.
<IfModule mod_setenvif.c>
    SetEnvIf X-Forwarded-Proto "^https\$" HTTPS=on
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    # En-tête Authorization transmis à PHP.
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Fichiers cachés (.env, .user.ini, repère d’installation…) : interdits, sauf .well-known.
    RewriteRule (^|/)\.(?!well-known/) - [F,L]

    # API et Sanctum : Laravel.
    RewriteRule ^(api|sanctum)(/.*)?\$ index.php [L]

    # Fichier existant du SPA : servi tel quel ; fichier du SPA absent : 404, jamais la page HTML.
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]
    RewriteRule ^assets/ - [R=404,L]

    # Toute autre adresse : le SPA, qui gère ses propres routes.
    RewriteRule ^ index.html [L]
</IfModule>

# En-têtes de sécurité (02-TRD §6.4), comme infra/nginx/cga-connect.conf.template.
<IfModule mod_headers.c>
    Header always set Strict-Transport-Security "max-age=$HSTS_MAX_AGE_SECONDS; includeSubDomains"
    Header always set Content-Security-Policy "$CSP_POLICY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "DENY"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    <Files "index.html">
        Header set Cache-Control "no-cache"
    </Files>
</IfModule>
APACHE
)
replace_block "$DOCROOT/.htaccess" "# BEGIN CGA Connect" "# END CGA Connect" "$HTACCESS"

USER_INI=$(cat <<INI
upload_max_filesize = ${UPLOAD_MAX_MB}M
post_max_size = ${UPLOAD_MAX_MB}M
memory_limit = $PHP_MEMORY_LIMIT
max_execution_time = $REQUEST_TIMEOUT_SECONDS
INI
)
replace_block "$DOCROOT/.user.ini" "; BEGIN CGA Connect" "; END CGA Connect" "$USER_INI"

mkdir -p "$DOCROOT/assets"
cp -a "$RELEASE_DIR/frontend-dist/assets/." "$DOCROOT/assets/"
cat > "$DOCROOT/assets/.htaccess" <<APACHE
# Fichiers du SPA dont le nom porte une empreinte : mis en cache (infra/cpanel/install.sh).
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=$ASSETS_CACHE_SECONDS, immutable"
</IfModule>
APACHE
find "$RELEASE_DIR/frontend-dist" -mindepth 1 -maxdepth 1 ! -name assets ! -name index.html \
    -exec cp -a {} "$DOCROOT/" \;
cp "$RELEASE_DIR/frontend-dist/index.html" "$DOCROOT/index.html.cga-new"
mv -f "$DOCROOT/index.html.cga-new" "$DOCROOT/index.html"
echo "$REVISION $RELEASE" > "$DOCROOT_MARK"

# Fichiers du SPA d'anciennes versions.
shopt -s nullglob
for asset in "$DOCROOT"/assets/*; do
    [[ -e "$RELEASE_DIR/frontend-dist/assets/$(basename "$asset")" ]] || rm -rf -- "$asset"
done
shopt -u nullglob

# 8. Workers et planificateur : un cron chaque minute (pas de Supervisor sur le mutualisé). Les autres
#    lignes du crontab sont gardées telles quelles ; une copie est faite avant modification.
CRON_BLOCK=$(cat <<CRON
* * * * * cd $APP_DIR/current/backend && $PHP_BIN artisan schedule:run >> /dev/null 2>&1
* * * * * cd $APP_DIR/current/backend && flock -n $APP_DIR/queue.lock $PHP_BIN artisan queue:work --queue=$QUEUES --stop-when-empty --max-time=$WORKER_MAX_TIME_SECONDS --sleep=$WORKER_SLEEP_SECONDS >> /dev/null 2>&1
CRON
)
current_cron=$(crontab -l 2>/dev/null || true)
printf '%s\n' "$current_cron" > "$APP_DIR/crontab-avant-$RELEASE.bak"
{
    printf '%s\n' "$current_cron" | awk -v b="$CRON_BEGIN" -v e="$CRON_END" \
        '$0 == b { skip = 1; next } $0 == e { skip = 0; next } !skip'
    printf '%s\n%s\n%s\n' "$CRON_BEGIN" "$CRON_BLOCK" "$CRON_END"
} | crontab -
log "Cron : planificateur et workers chaque minute"

# Les workers en cours finissent leur Job puis s'arrêtent ; le cron suivant les relance sur la nouvelle version.
"$PHP_BIN" "$APP_DIR/current/backend/artisan" queue:restart

# 9. Anciennes versions : on garde les KEEP_RELEASES dernières.
find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r \
    | tail -n +"$((KEEP_RELEASES + 1))" \
    | while read -r old; do rm -rf "${APP_DIR:?}/releases/$old"; done

# 10. Contrôles par le domaine public : point de santé, SPA, connexion d'un compte de démonstration.
log "Contrôles"
ORIGIN="https://$DOMAIN"
health=$(curl -sS --max-time "$CHECK_TIMEOUT_SECONDS" "$ORIGIN/api/health" 2>&1 || true)
printf '%s' "$health" | "$PHP_BIN" -r '
    $report = json_decode(stream_get_contents(STDIN), true);
    if (! is_array($report) || ! isset($report["checks"])) {
        echo "  Point de santé : réponse illisible. Dans cPanel, choisir PHP 8.3 pour le domaine (MultiPHP Manager), puis relancer.\n";
        exit;
    }
    foreach ($report["checks"] as $name => $check) {
        $error = isset($check["error"]) ? " (".$check["error"].")" : "";
        printf("  %-12s %s%s\n", $name, $check["status"], $error);
    }
    echo "  (libreoffice en échec : attendu sur le mutualisé, sans production de PDF)\n";
' | tee -a "$LOG_FILE"

spa_code=$(curl -sS --max-time "$CHECK_TIMEOUT_SECONDS" -o /dev/null -w '%{http_code}' "$ORIGIN/connexion" || true)
log "Interface ($ORIGIN/connexion) : HTTP $spa_code"

if [[ "$SEED_DEMO" == true ]]; then
    jar=$(mktemp)
    demo_password=$(grep -E '^SEED_DEMO_PASSWORD=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "'")
    curl -sS --max-time "$CHECK_TIMEOUT_SECONDS" -c "$jar" -b "$jar" -o /dev/null \
        -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" "$ORIGIN/sanctum/csrf-cookie" || true
    xsrf=$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" | "$PHP_BIN" -r 'echo urldecode(trim(stream_get_contents(STDIN)));')
    login_code=$(curl -sS --max-time "$CHECK_TIMEOUT_SECONDS" -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' \
        -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Origin: $ORIGIN" \
        -H "Referer: $ORIGIN/" -H "X-XSRF-TOKEN: $xsrf" \
        --data "{\"identifier\":\"$SMOKE_LOGIN_IDENTIFIER\",\"password\":\"$demo_password\"}" \
        "$ORIGIN/api/v1/auth/login" || true)
    me_code=$(curl -sS --max-time "$CHECK_TIMEOUT_SECONDS" -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' \
        -H 'Accept: application/json' -H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" "$ORIGIN/api/v1/me" || true)
    rm -f "$jar"
    unset demo_password
    log "Connexion de démonstration ($SMOKE_LOGIN_IDENTIFIER) : connexion HTTP $login_code, session HTTP $me_code"
fi

log "Terminé : $ORIGIN (version $REVISION)"
if [[ "$FIRST_INSTALL" == true && "$SEED_DEMO" == true ]]; then
    echo
    echo "Comptes de démonstration : 0700000001 Présidente CNCGA, 0700000002 gérant DC-KNOWING,"
    echo "0700000004 comptable, 0700000009 administrateur de la plateforme, 0700000011 adhérent."
    echo "Leur mot de passe (à garder pour vous) : grep SEED_DEMO_PASSWORD $ENV_FILE"
fi
