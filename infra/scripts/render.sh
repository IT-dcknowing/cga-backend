#!/usr/bin/env bash
# Produit les fichiers de configuration du serveur à partir des gabarits de infra/ et de infra.env.
# Seules les variables déclarées dans infra.env sont remplacées : les variables de Nginx
# ($host, $uri…) et de Supervisor (%(process_num)02d) restent intactes.
#
# Usage : sudo infra/scripts/render.sh [infra.env] [dossier de sortie]
#   par défaut : /etc/cga-connect/infra.env et /etc/cga-connect/rendered
set -euo pipefail

INFRA_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
INFRA_ENV=${1:-/etc/cga-connect/infra.env}
OUT_DIR=${2:-/etc/cga-connect/rendered}

if [[ ! -r "$INFRA_ENV" ]]; then
    echo "Fichier de variables introuvable : $INFRA_ENV (modèle : infra/infra.env.example)" >&2
    exit 1
fi

set -a
# shellcheck disable=SC1090
source "$INFRA_ENV"
set +a

variables=$(grep -oE '^[A-Z][A-Z0-9_]*=' "$INFRA_ENV" | tr -d '=' | sed 's/.*/${&}/' | tr '\n' ' ')

while IFS= read -r template; do
    relative=${template#"$INFRA_DIR"/}
    target="$OUT_DIR/${relative%.template}"
    mkdir -p "$(dirname "$target")"
    envsubst "$variables" < "$template" > "$target"

    if grep -qE '\$\{[A-Z][A-Z0-9_]*\}' "$target"; then
        echo "Variable non définie dans $INFRA_ENV : $(grep -oE '\$\{[A-Z][A-Z0-9_]*\}' "$target" | sort -u | tr '\n' ' ')" >&2
        exit 1
    fi

    echo "$target"
done < <(find "$INFRA_DIR" -name '*.template' -type f | sort)
