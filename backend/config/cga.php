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

    /*
    |--------------------------------------------------------------------------
    | Données initiales (PlatformSeeder)
    |--------------------------------------------------------------------------
    |
    | Organisation technique « plateforme », CNCGA et premier administrateur.
    | Valeurs provisoires, modifiables ensuite à l'écran (RG-DATA-01).
    |
    */

    'seed' => [
        'platform' => [
            'name' => env('SEED_PLATFORM_NAME', 'CGA Connect'),
            'city' => env('SEED_PLATFORM_CITY', 'Abidjan'),
            'phone' => env('SEED_PLATFORM_PHONE', '+2250000000000'),
        ],
        'cncga' => [
            'name' => env('SEED_CNCGA_NAME', 'Collectif National des Centres de Gestion Agréés'),
            'sigle' => env('SEED_CNCGA_SIGLE', 'CNCGA'),
            'city' => env('SEED_CNCGA_CITY', 'Abidjan'),
            'phone' => env('SEED_CNCGA_PHONE', '+2250000000000'),
        ],
        // Premier administrateur de la plateforme : créé seulement si son téléphone est renseigné.
        'platform_admin' => [
            'first_name' => env('SEED_PLATFORM_ADMIN_FIRST_NAME', 'Administrateur'),
            'last_name' => env('SEED_PLATFORM_ADMIN_LAST_NAME', 'Plateforme'),
            'phone' => env('SEED_PLATFORM_ADMIN_PHONE'),
            'email' => env('SEED_PLATFORM_ADMIN_EMAIL'),
        ],
    ],

];
