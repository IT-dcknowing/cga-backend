<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registre des paramètres (02-TRD §7.5, 05-BACKEND-SCHEMA §16)
    |--------------------------------------------------------------------------
    |
    | Chaque clé lue par le code est déclarée ici avec son type, son niveau
    | (platform, network, cga) et sa valeur par défaut. La table « settings »
    | (étapes 0.6 et 0.7) permettra de la modifier à l'écran ; d'ici là, la
    | valeur par défaut s'applique. Une clé non déclarée ne peut pas être lue.
    |
    */

    'settings' => [

        'files' => [
            // Alerte quand le disque de stockage dépasse ce pourcentage d'occupation (02-TRD §6.4).
            'disk_alert_percent' => [
                'type' => 'integer',
                'level' => 'platform',
                'default' => 80,
            ],
        ],

    ],

];
