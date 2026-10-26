<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horaires des tâches planifiées (02-TRD §7.6, §11)
    |--------------------------------------------------------------------------
    |
    | Heures au format HH:MM, fuseau de l'application (Africa/Abidjan).
    | Les tâches elles-mêmes sont déclarées dans routes/console.php.
    |
    */

    // Partitions mensuelles des journaux, préparées à l'avance.
    'logs_partitions' => env('SCHEDULE_LOGS_PARTITIONS', '00:05'),

    // Échéances d'honoraires de la période en cours (F-PAY-04).
    'fees_generate' => env('SCHEDULE_FEES_GENERATE', '01:15'),

    // Statuts datés : pièces expirées (RG-PJ-01), honoraires impayés (RG-PAY-03), puis rapports en retard.
    'statuses_refresh' => env('SCHEDULE_STATUSES_REFRESH', '01:30'),

    // Nouveaux essais de certification FNE (RG-FNE-04) : expression cron, toutes les 15 minutes.
    'fne_retry' => env('SCHEDULE_FNE_RETRY', '*/15 * * * *'),

    // Fin de la consultation seule des adhérents radiés (RG-ADH-12).
    'radiation_expiry' => env('SCHEDULE_RADIATION_EXPIRY', '03:30'),

];
