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

    // Fin de la consultation seule des adhérents radiés (RG-ADH-12).
    'radiation_expiry' => env('SCHEDULE_RADIATION_EXPIRY', '03:30'),

];
