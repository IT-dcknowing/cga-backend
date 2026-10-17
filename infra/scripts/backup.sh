#!/usr/bin/env bash
# Sauvegarde de nuit de CGA Connect (02-TRD §12.3) :
#   - la base : pg_dump chiffré ;
#   - le stockage (storage/app/private) : archive chiffrée, complète le jour BACKUP_FULL_WEEKDAY,
#     incrémentale les autres jours (GNU tar --listed-incremental) ;
#   - rétention BACKUP_RETENTION_DAYS, puis copie hors du serveur (BACKUP_REMOTE).
# Le chiffrement utilise la clé publique BACKUP_GPG_RECIPIENT : la clé privée, qui seule permet
# de restaurer, n'est jamais sur le serveur. Restauration : infra/README.md.
#
# Usage (root) : infra/scripts/backup.sh     variables : INFRA_ENV (défaut /etc/cga-connect/infra.env)
set -euo pipefail

INFRA_ENV=${INFRA_ENV:-/etc/cga-connect/infra.env}
# shellcheck disable=SC1090
source "$INFRA_ENV"

STAMP=$(date +%Y%m%d-%H%M%S)
STORAGE_DIR="$APP_DIR/shared/storage/app/private"
DB_DIR="$BACKUP_DIR/db"
FILES_DIR="$BACKUP_DIR/files"
CURRENT_SET="$FILES_DIR/current"

log() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*"
    logger -t cga-backup -- "$*" 2>/dev/null || true
}

on_error() {
    log "ÉCHEC de la sauvegarde $STAMP (ligne $1)"
    rm -f "$DB_DIR"/*.partial "$FILES_DIR"/*/*.partial
}
trap 'on_error $LINENO' ERR

encrypt_to() {
    gpg --batch --yes --trust-model always --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$1"
}

umask 077
mkdir -p "$DB_DIR" "$FILES_DIR"
exec 9>"$BACKUP_DIR/.backup.lock"
flock -n 9 || { log "Une sauvegarde est déjà en cours"; exit 1; }

log "Sauvegarde $STAMP"

# 1. Base de données (format personnalisé de pg_dump, restaurable par pg_restore).
db_file="$DB_DIR/$DB_NAME-$STAMP.dump.gpg"
runuser -u postgres -- pg_dump --format=custom "$DB_NAME" | encrypt_to "$db_file.partial"
mv "$db_file.partial" "$db_file"
log "Base : $db_file"

# 2. Stockage : un jeu = une archive complète suivie de ses archives incrémentales.
if [[ "$(date +%u)" == "$BACKUP_FULL_WEEKDAY" || ! -f "$CURRENT_SET/snapshot.snar" ]]; then
    set_dir="$FILES_DIR/$STAMP"
    mkdir -p "$set_dir"
    ln -sfn "$set_dir" "$CURRENT_SET"
    level=full
else
    set_dir=$(readlink -f "$CURRENT_SET")
    level=incremental
fi
files_file="$set_dir/$STAMP-$level.tar.gz.gpg"
# Code 1 de tar : un fichier a changé pendant la lecture ; il sera repris par la prochaine archive.
{ tar --create --gzip --listed-incremental="$set_dir/snapshot.snar" --directory="$STORAGE_DIR" . || [[ $? -eq 1 ]]; } \
    | encrypt_to "$files_file.partial"
mv "$files_file.partial" "$files_file"
log "Stockage ($level) : $files_file"

# 3. Rétention : un jeu n'est supprimé que si sa dernière archive dépasse la durée de rétention.
find "$DB_DIR" -name '*.dump.gpg' -type f -mtime +"$BACKUP_RETENTION_DAYS" -delete
current_target=$(readlink -f "$CURRENT_SET")
find "$FILES_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$BACKUP_RETENTION_DAYS" -print \
    | while read -r old_set; do
        [[ "$(readlink -f "$old_set")" == "$current_target" ]] || rm -rf "$old_set"
    done

# 4. Copie hors du serveur (sans --delete : la rétention de la copie distante se règle sur place).
if [[ -n "${BACKUP_REMOTE:-}" ]]; then
    rsync --archive --exclude='.backup.lock' -e 'ssh -o BatchMode=yes' "$BACKUP_DIR/" "$BACKUP_REMOTE/"
    log "Copie hors du serveur : $BACKUP_REMOTE"
else
    log "ALERTE : aucune copie hors du serveur (BACKUP_REMOTE est vide)"
fi

log "Sauvegarde $STAMP terminée"
