# Procédure de restauration des sauvegardes

Cette procédure s'applique aux sauvegardes produites par `backup:run` (02-TRD §12.3). Les sauvegardes sont chiffrées avec la clé publique GPG du destinataire ; la clé privée de restauration doit être gardée hors du serveur applicatif et protégée par l'équipe d'exploitation.

## Prérequis

- Utiliser un serveur de restauration isolé et une base vide. Ne jamais restaurer directement sur la production.
- Disposer de la clé privée GPG correspondante, de `gpg`, `pg_restore` et `tar`.
- Récupérer depuis la copie hors serveur le dump `.dump.gpg`, toutes les archives du jeu de fichiers choisi et le fichier `snapshot.snar` correspondant.
- Définir les secrets uniquement dans l'environnement protégé de la session de restauration ; ne pas les placer dans les journaux ou dans un dépôt.

## Restaurer la base

1. Déchiffrer le dump vers un fichier temporaire protégé : `gpg --output database.dump --decrypt <fichier>.dump.gpg`.
2. Créer une base vide de restauration et restaurer avec `pg_restore --no-owner --no-privileges --dbname=<base_de_restauration> database.dump`.
3. Vérifier le code de sortie, les migrations (`php artisan migrate:status`) et les enregistrements de contrôle attendus.
4. Supprimer le dump temporaire après validation.

## Restaurer les fichiers

1. Créer un répertoire vide de restauration.
2. Déchiffrer l'archive complète du jeu choisi vers un flux puis l'extraire dans ce répertoire : `gpg --decrypt <jeu>-full.tar.gz.gpg | tar --extract --gzip --directory=<répertoire_de_restauration>`.
3. Appliquer, dans l'ordre chronologique, chaque archive incrémentale de ce même jeu avec `gpg --decrypt <jeu>-incremental.tar.gz.gpg | tar --extract --gzip --listed-incremental=/dev/null --directory=<répertoire_de_restauration>`.
4. Vérifier les fichiers et sommes de contrôle métier avant de reconnecter l'application au stockage restauré.

## Test trimestriel et preuve

L'équipe d'exploitation consigne la date, les identifiants des sauvegardes utilisées, la durée, le résultat des contrôles de base et de fichiers, et l'approbateur dans le registre d'exploitation. Une restauration n'est déclarée réussie qu'après vérification de la base et des fichiers sur l'environnement isolé. Ce dépôt décrit la procédure ; il ne constitue pas une preuve qu'une restauration en recette a déjà été exécutée.
