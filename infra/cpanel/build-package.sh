#!/usr/bin/env bash
# Construit, sur un poste de développement, le paquet de la recette cPanel (infra/cpanel/README.md) :
# backend de la version commitée, SPA construit (le serveur n'a pas Node), scripts d'installation et
# variables du serveur. Le paquet ne contient ni dépendances (composer install se fait sur le
# serveur) ni mot de passe.
#
# Usage, depuis la racine du dépôt : infra/cpanel/build-package.sh <cpanel.env> <dossier de sortie>
set -euo pipefail

USAGE="Usage : infra/cpanel/build-package.sh <cpanel.env> <dossier de sortie>"
ENV_SOURCE=${1:?$USAGE}
OUT_DIR=${2:?$USAGE}
# Chemins Windows (C:\…) convertis pour tar sous Git Bash.
if command -v cygpath > /dev/null; then
    ENV_SOURCE=$(cygpath -u "$ENV_SOURCE")
    OUT_DIR=$(cygpath -u "$OUT_DIR")
fi
[[ -f "$ENV_SOURCE" ]] || { echo "Fichier de variables introuvable : $ENV_SOURCE" >&2; exit 1; }

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"
if [[ -n "$(git status --porcelain)" ]]; then
    echo "Modifications non commitées : le paquet ne prend que la version commitée. Commiter d'abord." >&2
    exit 1
fi

REVISION=$(git rev-parse HEAD)
SHORT=$(git rev-parse --short HEAD)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
PACKAGE="$WORK/cga-connect-recette"
mkdir -p "$PACKAGE"

echo "Backend et scripts de la version $SHORT"
git archive HEAD backend | tar -x -C "$PACKAGE"
git archive HEAD infra/cpanel | tar -x -C "$WORK"
cp "$WORK/infra/cpanel/install.sh" "$WORK/infra/cpanel/set-env.php" "$WORK/infra/cpanel/db-check.php" "$PACKAGE/"

echo "SPA (npm run build, même domaine que l'API : frontend/.env.production)"
(
    cd frontend
    [[ -d node_modules ]] || npm ci --no-audit --no-fund
    npm run build
)
cp -r frontend/dist "$PACKAGE/frontend-dist"

tr -d '\r' < "$ENV_SOURCE" > "$PACKAGE/cpanel.env"
echo "$REVISION" > "$PACKAGE/REVISION"

mkdir -p "$OUT_DIR"
ARCHIVE="$OUT_DIR/cga-connect-recette-$SHORT.tar.gz"
tar -czf "$ARCHIVE" -C "$WORK" cga-connect-recette
echo "Paquet : $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
