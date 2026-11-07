# Procédure de restauration des sauvegardes

Cette procédure s'applique aux sauvegardes produites par `backup:run` (02-TRD §12.3). Les sauvegardes sont chiffrées avec la clé publique GPG du destinataire ; la clé privée de restauration doit être gardée hors du serveur applicatif et protégée par l'équipe d'exploitation.

## Contenu d'une sauvegarde

Dans le dossier `BACKUP_PATH` (par défaut `storage/backups`) :

| Fichier | Contenu |
|---|---|
| `db/{base}-{AAAAMMJJ-HHMMSS}.dump.gpg` | Base PostgreSQL (`pg_dump --format=custom`), chiffrée |
| `db/{base}-{AAAAMMJJ-HHMMSS}.manifest.json` | Manifeste : nombre de lignes de chaque table (lu dans le même instantané que le dump), nombre et taille des fichiers archivés. Aucune donnée nominative. |
| `files/{jeu}/{AAAAMMJJ-HHMMSS}-full.tar.gz.gpg` | Archive complète du stockage `storage/app/private` (premier fichier d'un jeu) |
| `files/{jeu}/{AAAAMMJJ-HHMMSS}-incremental.tar.gz.gpg` | Archives incrémentales du même jeu |

## Test de restauration trimestriel (commande `backup:restore-test`)

Le test se fait sur un **serveur isolé** (jamais la production), où l'application est installée avec un `.env` dont `APP_ENV` figure dans `BACKUP_RESTORE_ENVIRONMENTS` (par défaut `local,testing,restore,recette`).

1. Sur le serveur isolé, importer la **clé privée** GPG dans le trousseau de l'utilisateur qui lance la commande, et la déverrouiller (agent GPG) pour que `gpg --batch --decrypt` fonctionne sans saisie.
2. Copier depuis la copie hors serveur le dossier des sauvegardes vers `BACKUP_PATH` (au minimum : le dump, son manifeste et tout le jeu de fichiers correspondant).
3. Créer une **base vide** de restauration, différente de celle de l'application : `createdb cga_restore_AAAAMMJJ`.
4. Lancer :

   ```bash
   php artisan backup:restore-test cga_restore_AAAAMMJJ            # sauvegarde la plus récente
   php artisan backup:restore-test cga_restore_AAAAMMJJ --dump=cga_connect-20261108-040000.dump.gpg
   ```

   La commande :
   - refuse un environnement non autorisé, un nom de base invalide, la base de l'application et une base non vide ;
   - déchiffre le dump au fil de l'eau vers `pg_restore` (aucune copie en clair sur le disque) ;
   - compare, table par table, le nombre de lignes restaurées avec le manifeste ;
   - déchiffre et extrait l'archive complète puis les incrémentales du jeu, **jusqu'à celle de la sauvegarde testée**, dans `BACKUP_RESTORE_WORK_PATH`, contrôle le nombre et la taille des fichiers, puis efface ce dossier ;
   - écrit un rapport JSON dans `BACKUP_RESTORE_REPORT_PATH` et affiche le résultat.

   Code de sortie `0` : restauration conforme. Code `1` : au moins un écart (détaillé à l'écran et dans le rapport). Une erreur (outil absent, clé indisponible, sauvegarde introuvable) arrête la commande avec son message.
5. Supprimer la base de restauration après validation : `dropdb cga_restore_AAAAMMJJ`.

## Restauration manuelle (sinistre)

### Restaurer la base

1. Créer une base vide.
2. Restaurer sans fichier en clair : `gpg --batch --decrypt <fichier>.dump.gpg | pg_restore --no-owner --no-privileges --dbname=<base>`.
3. Vérifier le code de sortie, `php artisan migrate:status`, et comparer quelques comptes avec le manifeste.

### Restaurer les fichiers

1. Créer un répertoire vide de restauration.
2. Extraire l'archive complète du jeu choisi : `gpg --batch --decrypt <jeu>-full.tar.gz.gpg | tar --extract --gzip --listed-incremental=/dev/null --directory=<répertoire>`.
3. Appliquer, dans l'ordre chronologique, chaque archive incrémentale de ce même jeu avec la même commande.
4. Contrôler le nombre et la taille des fichiers avec le manifeste avant de reconnecter l'application au stockage restauré.

## Registre d'exploitation (preuve du test)

À chaque test trimestriel, l'équipe d'exploitation consigne :

| Champ | Source |
|---|---|
| Date du test | `tested_at` du rapport |
| Serveur isolé et environnement | `environment` |
| Sauvegarde testée | `database_file`, `files_set`, `archives` |
| Durée | `duration_seconds` |
| Résultat base | `tables.checked`, `tables.mismatches` |
| Résultat fichiers | `files.restored_count`, `files.ok` |
| Résultat global | `status` (`passed` / `failed`) |
| Approbateur | nom et signature |

Une restauration n'est déclarée réussie qu'avec un rapport `status = passed`. Ce dépôt fournit la procédure et l'outil ; il ne constitue pas, à lui seul, la preuve qu'une restauration a été exécutée en recette.
