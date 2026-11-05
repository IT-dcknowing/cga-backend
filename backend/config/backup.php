<?php

/*
|--------------------------------------------------------------------------
| Sauvegardes (02-TRD §12.3, étape 9.1)
|--------------------------------------------------------------------------
|
| backup:run chaque nuit (schedule.backup_run) : la base (pg_dump au format personnalisé) et le stockage
| des fichiers (archive complète le jour full_weekday, incrémentale les autres jours), chiffrés par la clé
| publique GPG gpg_recipient — la clé privée, qui seule permet de restaurer, n'est jamais sur le serveur —,
| rétention de retention_days jours, puis copie hors du serveur (remote, rsync sur SSH). Le point de santé
| signale une sauvegarde de plus de max_age_hours heures. Désactivé par défaut (poste de développement).
| Chaque outil est une commande et ses premiers arguments (les tests y mettent des scripts PHP).
|
*/

return [

    'enabled' => (bool) env('BACKUP_ENABLED', false),

    'directory' => env('BACKUP_PATH', storage_path('backups')),

    'gpg_recipient' => env('BACKUP_GPG_RECIPIENT'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    // Jour de l'archive complète des fichiers (1 = lundi … 7 = dimanche).
    'full_weekday' => (int) env('BACKUP_FULL_WEEKDAY', 7),

    // Copie hors du serveur, ex. sauvegarde@stockage.example:/srv/cga-connect ; vide : pas de copie (alerte).
    'remote' => env('BACKUP_REMOTE'),

    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),

    'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 3600),

    // Longueur gardée du message d'une sauvegarde en échec.
    'error_max_length' => 2000,

    'commands' => [
        'pg_dump' => [env('BACKUP_PG_DUMP_BINARY', 'pg_dump')],
        'gpg' => [env('BACKUP_GPG_BINARY', 'gpg')],
        'tar' => [env('BACKUP_TAR_BINARY', 'tar')],
        'rsync' => [env('BACKUP_RSYNC_BINARY', 'rsync')],
    ],

    // Options SSH de la copie hors du serveur (clé de l'utilisateur de l'application, sans mot de passe).
    'ssh_command' => env('BACKUP_SSH_COMMAND', 'ssh -o BatchMode=yes'),

];
