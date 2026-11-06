# Exploitation — sauvegarde et restauration CGA Connect (lot 9.1)

Cette procédure est manuelle : elle ne prouve pas qu'une sauvegarde a été exécutée et n'active aucune tâche automatique.

## Pré-requis

- Installer `pg_dump`, `pg_restore`, `tar`, `sha256sum` et `age` sur l'hôte d'exploitation.
- Définir un compte PostgreSQL dédié, un staging privé en mode 0700 et un stockage distant privé.
- Conserver le destinataire age public dans la configuration ; conserver la clé privée hors serveur et hors sauvegardes.
- Coordonner une fenêtre de maintenance ou des snapshots cohérents entre PostgreSQL et `FILES_ROOT`.
- Ne jamais inclure `.env`, les secrets applicatifs ou la clé privée dans l'archive.

## Sauvegarde

Exécuter avec un compte de service dédié et les variables d'environnement PostgreSQL requises :

```bash
set -euo pipefail
umask 077
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
STAGING="/var/backups/cga-staging/$STAMP"
FILES_ROOT="/srv/cga/storage/app/private"
DB_NAME="${DB_DATABASE:?DB_DATABASE requis}"
DB_HOST="${DB_HOST:?DB_HOST requis}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USERNAME:?DB_USERNAME requis}"
AGE_RECIPIENT="${BACKUP_AGE_RECIPIENT:?destinataire age requis}"

install -d -m 0700 "$STAGING"
pg_dump --format=custom --no-owner --no-acl \
  --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" \
  --file="$STAGING/database.dump" "$DB_NAME"

tar --create --file="$STAGING/private-files.tar" \
  --directory="$(dirname "$FILES_ROOT")" "$(basename "$FILES_ROOT")"

(
  cd "$STAGING"
  sha256sum database.dump private-files.tar > SHA256SUMS
  printf 'created_at_utc=%s\n' "$STAMP" > MANIFEST.txt
  printf 'database=%s\n' "$DB_NAME" >> MANIFEST.txt
  printf 'application_release=%s\n' "${SENTRY_RELEASE:-unknown}" >> MANIFEST.txt
)

tar --create --file="$STAGING/cga-backup.tar" \
  --directory="$STAGING" database.dump private-files.tar SHA256SUMS MANIFEST.txt
age --recipient "$AGE_RECIPIENT" \
  --output="$STAGING/cga-backup.tar.age" "$STAGING/cga-backup.tar"
sha256sum "$STAGING/cga-backup.tar.age" > "$STAGING/cga-backup.tar.age.sha256"
```

Transférer uniquement l'archive `.age` et son empreinte vers le stockage distant. Vérifier le transfert avant de supprimer le staging en clair. Les opérations doivent être coordonnées : `pg_dump` puis `tar` ne garantissent pas à eux seuls la cohérence des fichiers modifiés simultanément.

## Vérification et restauration isolée

1. Télécharger l'archive distante dans un environnement isolé.
2. Vérifier son empreinte, déchiffrer avec la clé privée hors ligne, puis vérifier `SHA256SUMS`.
3. Contrôler l'archive PostgreSQL avec `pg_restore --list database.dump`.
4. Restaurer dans une base dédiée, jamais directement en production :

```bash
age --decrypt --identity "$BACKUP_AGE_IDENTITY" \
  --output cga-backup.tar archive/cga-backup.tar.age
tar --extract --file=cga-backup.tar
(cd extracted && sha256sum --check SHA256SUMS)
createdb --host="$RESTORE_DB_HOST" --username="$RESTORE_DB_USER" cga_restore
pg_restore --exit-on-error --no-owner --no-acl \
  --host="$RESTORE_DB_HOST" --username="$RESTORE_DB_USER" \
  --dbname=cga_restore extracted/database.dump
```

5. Extraire les fichiers dans un répertoire privé isolé, sans connecter l'application de production à ce stockage.

## Contrôles post-restauration

- Vérifier extensions PostgreSQL, migrations et tables critiques.
- Comparer les volumes de données des organisations, adhérents, adhésions, documents, fichiers et journaux.
- Vérifier des échantillons de `files.path` et recalculer leur SHA-256 pour les comparer à `files.sha256`.
- Vérifier les références de fichiers (logo, signature, cachet, pièces, versions documentaires).
- Exécuter les tests de santé, d'authentification et d'isolation multi-CGA.
- Consigner le résultat, les durées et les écarts sans journaliser de secret.

## Décisions d'exploitation requises

Avant automatisation, faire approuver et consigner le RPO, le RTO, la rétention quotidienne/hebdomadaire/mensuelle, la fréquence des exercices, le stockage hors site, le responsable des clés et leur procédure de rotation. Le lot 9.1 ne peut être déclaré opérationnel qu'après un exercice de restauration réussi.
