#!/usr/bin/env bash
# Déploie une version de CGA Connect sur le serveur (02-TRD §12.2) :
# récupération du code, composer install --no-dev, build du SPA, migrations --force,
# config:cache, route:cache, bascule du lien current, rechargement de PHP-FPM, queue:restart.
#
# Chaque version est construite à part dans releases/<date> ; le lien current ne bascule
# qu'une fois tout prêt. Un échec en cours de route laisse la version en service intacte.
#
# Usage (utilisateur APP_USER) : infra/scripts/deploy.sh [branche | étiquette vX.Y.Z | commit]
#   variables : INFRA_ENV (défaut /etc/cga-connect/infra.env)
set -euo pipefail

INFRA_ENV=${INFRA_ENV:-/etc/cga-connect/infra.env}
# shellcheck disable=SC1090
source "$INFRA_ENV"

REF=${1:-$DEPLOY_REF}
RELEASE=$(date +%Y%m%d%H%M%S)
RELEASE_DIR="$APP_DIR/releases/$RELEASE"
SHARED_DIR="$APP_DIR/shared"
MIRROR_DIR="$APP_DIR/repo.git"

log() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*"
    logger -t cga-deploy -- "$*" 2>/dev/null || true
}

fail() {
    log "ÉCHEC du déploiement de $REF : $*"
    exit 1
}

exec 9>"$APP_DIR/.deploy.lock"
flock -n 9 || fail "un autre déploiement est en cours"

cleanup_failed_release() {
    log "Version $RELEASE abandonnée ; la version en service n'a pas changé."
    rm -rf "$RELEASE_DIR"
}
trap cleanup_failed_release ERR

log "Déploiement de $REF ($DEPLOY_ENV) dans $RELEASE_DIR"

# 1. Code : miroir local du dépôt, puis extraction de la version demandée.
if [[ ! -d "$MIRROR_DIR" ]]; then
    git clone --mirror "$REPO_URL" "$MIRROR_DIR"
fi
git --git-dir="$MIRROR_DIR" remote update --prune
COMMIT=$(git --git-dir="$MIRROR_DIR" rev-parse --verify "$REF^{commit}") || fail "version inconnue : $REF"
mkdir -p "$RELEASE_DIR"
git --git-dir="$MIRROR_DIR" archive "$COMMIT" | tar -x -C "$RELEASE_DIR"
echo "$COMMIT" > "$RELEASE_DIR/REVISION"
log "Commit $COMMIT"

# 2. Fichiers partagés entre les versions : configuration et stockage (documents, pièces, journaux).
ln -s "$SHARED_DIR/.env" "$RELEASE_DIR/backend/.env"
rm -rf "$RELEASE_DIR/backend/storage"
ln -s "$SHARED_DIR/storage" "$RELEASE_DIR/backend/storage"

# 3. Backend.
cd "$RELEASE_DIR/backend"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# 4. SPA, construit pour le même domaine (frontend/.env.production) ; les dépendances ne sont pas gardées.
cd "$RELEASE_DIR/frontend"
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

# 5. Migrations (uniquement additives en production, 02-TRD §12.2) et caches de Laravel.
cd "$RELEASE_DIR/backend"
"$PHP_BIN" artisan migrate --force --no-interaction
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache

# 6. Bascule atomique du lien current.
ln -sfn "$RELEASE_DIR" "$APP_DIR/current.next"
mv -Tf "$APP_DIR/current.next" "$APP_DIR/current"
trap - ERR
log "Version $RELEASE en service"

# 7. PHP-FPM recharge le code (OPcache) ; les workers finissent leur Job puis redémarrent sur la nouvelle version.
sudo systemctl reload "$PHP_FPM_SERVICE"
"$PHP_BIN" "$APP_DIR/current/backend/artisan" queue:restart

# 8. Anciennes versions : on garde les KEEP_RELEASES dernières.
find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r \
    | tail -n +"$((KEEP_RELEASES + 1))" \
    | while read -r old; do rm -rf "${APP_DIR:?}/releases/$old"; done

# 9. Contrôle final : le point de santé, demandé au Nginx de ce serveur, doit répondre 200.
if curl --silent --fail --max-time "$REQUEST_TIMEOUT_SECONDS" --resolve "$DOMAIN:443:127.0.0.1" \
    "https://$DOMAIN/api/health" > /dev/null; then
    log "Point de santé OK"
else
    fail "le point de santé ne répond pas 200 (https://$DOMAIN/api/health) ; voir infra/README.md, « Retour arrière »"
fi
