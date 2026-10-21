<?php

/*
 * Droits requis pour modifier un paramètre, selon son niveau (catalogue config/permissions.php).
 */
$platform = 'platform.settings.manage';

return [

    /*
    |--------------------------------------------------------------------------
    | Registre des paramètres (02-TRD §7.5, 05-BACKEND-SCHEMA §16)
    |--------------------------------------------------------------------------
    |
    | Chaque clé lue par le code est déclarée ici. Une clé non déclarée ne peut
    | être ni lue ni écrite (SettingsService). Pour chaque clé :
    | - type : integer, string, boolean, array ;
    | - level : platform (administrateur), network (CNCGA), cga (gérant), ou
    |   une liste de niveaux ; la valeur d'un CGA l'emporte sur celle du réseau ;
    | - default : valeur tant que rien n'est enregistré ;
    | - rules : règles de validation Laravel de la valeur ('' = la valeur
    |   elle-même, 'champ' = un champ d'une valeur structurée) ;
    | - permission : droit requis pour la modifier à l'écran.
    |
    */

    'settings' => [

        // Compteur incrémenté à chaque modification d'un paramètre (02-TRD §7.5) ; interne, jamais saisi.
        'settings' => [
            'version' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 0, 'internal' => true, 'permission' => null,
                'rules' => ['' => ['integer', 'min:0']],
            ],
        ],

        'files' => [
            'max_upload_mb' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 10, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:100']],
            ],
            'allowed_types' => [
                'type' => 'array', 'level' => 'platform', 'permission' => $platform,
                'default' => ['application/pdf', 'image/jpeg', 'image/png'],
                'rules' => ['' => ['array', 'min:1'], '*' => ['string', 'max:100']],
            ],
            'download_link_minutes' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 5, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:1440']],
            ],
            // Alerte quand le disque de stockage dépasse ce pourcentage d'occupation (02-TRD §6.4).
            'disk_alert_percent' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 80, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:100']],
            ],
        ],

        'pdf' => [
            'timeout_seconds' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 60, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:5', 'max:600']],
            ],
        ],

        'documents' => [
            'workers' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 1, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:16']],
            ],
        ],

        'rate_limit' => [
            'login' => [
                'type' => 'array', 'level' => 'platform', 'permission' => $platform,
                'default' => ['max_attempts' => 5, 'decay_minutes' => 15],
                'rules' => ['' => ['array:max_attempts,decay_minutes'], 'max_attempts' => ['required', 'integer', 'min:1'], 'decay_minutes' => ['required', 'integer', 'min:1']],
            ],
            'otp' => [
                'type' => 'array', 'level' => 'platform', 'permission' => $platform,
                'default' => ['max_attempts' => 3, 'decay_minutes' => 60],
                'rules' => ['' => ['array:max_attempts,decay_minutes'], 'max_attempts' => ['required', 'integer', 'min:1'], 'decay_minutes' => ['required', 'integer', 'min:1']],
            ],
            'verification' => [
                'type' => 'array', 'level' => 'platform', 'permission' => $platform,
                'default' => ['max_attempts' => 30, 'decay_minutes' => 1],
                'rules' => ['' => ['array:max_attempts,decay_minutes'], 'max_attempts' => ['required', 'integer', 'min:1'], 'decay_minutes' => ['required', 'integer', 'min:1']],
            ],
        ],

        'session' => [
            'timeout_agent_minutes' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 30, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:5', 'max:720']],
            ],
            'timeout_member_minutes' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 60, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:5', 'max:720']],
            ],
        ],

        'messaging' => [
            'send_window' => [
                'type' => 'array', 'level' => 'platform', 'permission' => $platform,
                'default' => ['from' => '07:00', 'to' => '20:00'],
                'rules' => ['' => ['array:from,to'], 'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i', 'after:value.from']],
            ],
        ],

        'logs' => [
            'activity_retention_months' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 24, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:240']],
            ],
            'audit_retention_months' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 60, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:240']],
            ],
            // Champs remplacés par *** dans les journaux (02-TRD §6.5).
            'redacted_fields' => [
                'type' => 'array', 'level' => 'platform', 'permission' => $platform,
                'default' => [
                    'password', 'password_confirmation', 'current_password', 'new_password', 'token', 'code', 'otp',
                    'secret', 'api_key', 'api_secret', 'client_secret', 'authorization', 'cookie', 'x-xsrf-token',
                ],
                'rules' => ['' => ['array'], '*' => ['string', 'max:60']],
            ],
        ],

        'pagination' => [
            'default' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 25, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:5', 'max:100']],
            ],
            'max' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 100, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:10', 'max:500']],
            ],
        ],

        // Dossiers des adhérents (M04).
        'members' => [
            // Contrôle « droit d'adhésion payé et confirmé » à la validation (RG-ADH-02) : branché avec les
            // paiements (Lot 3) ; d'ici là, désactivé.
            'require_entry_fee_payment' => [
                'type' => 'boolean', 'level' => 'platform', 'default' => false, 'permission' => $platform,
                'rules' => ['' => ['boolean']],
            ],
            // Délai d'annulation d'une radiation par le gérant (RG-ADH-11).
            'radiation_cancel_days' => [
                'type' => 'integer', 'level' => 'network', 'default' => 30, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
            // Accès d'un adhérent radié à ses documents, en consultation seule (RG-ADH-12), prolongeable par le CGA.
            'read_only_months' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 6, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:120']],
            ],
        ],

        // Adhérent du portefeuille CGA reclassé au régime réel (RG-ADH-08, 09) : alerte au gérant passé ce délai
        // sans radiation, jamais de blocage ni de radiation automatique (CR-04, RG-ADH-18).
        'rsi' => [
            'radiation_deadline_days' => [
                'type' => 'integer', 'level' => 'network', 'default' => 90, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
        ],

        'invitation' => [
            'validity_days' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 7, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:60']],
            ],
        ],

        'otp' => [
            'validity_minutes' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 10, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:60']],
            ],
            // Essais de saisie d'un code (02-TRD §6.1) ; la base en admet 3 au plus (otp_codes.attempts).
            'max_attempts' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 3, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:3']],
            ],
        ],

        // Abonnement des CGA (F-ORG-05, RG-ORG-07) : durée de l'essai offert, délai de suppression des
        // données après une résiliation (sous réserve des durées légales).
        'subscription' => [
            'trial_months' => [
                'type' => 'integer', 'level' => 'network', 'default' => 3, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:24']],
            ],
            'data_retention_months' => [
                'type' => 'integer', 'level' => 'network', 'default' => 6, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:120']],
            ],
        ],

        'queue' => [
            'alert_delay_minutes' => [
                'type' => 'integer', 'level' => 'platform', 'default' => 15, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:1', 'max:1440']],
            ],
        ],

        // Formats de numérotation (02-TRD §7.1, RG-ADH-03) : {YYYY} année, {ABR} abréviation du CGA,
        // {N:4} numéro sur 4 chiffres au moins.
        'numbering' => [
            'membership' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => '{YYYY}-{ABR}-{N:4}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:60', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
            'client' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => 'CAB-{ABR}-{N:5}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:60', 'regex:/\{N(:\d)?\}/']],
                ],
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
        // Jeu de démonstration (DemoSeeder) : comptes fictifs du poste de développement et de la
        // recette, jamais en production. Les essais de code s'y renouvellent chaque minute, pour
        // rejouer la collection Postman sans attendre une heure.
        'demo' => [
            'enabled' => (bool) env('SEED_DEMO', false),
            'password' => env('SEED_DEMO_PASSWORD', 'Demo-Cga-2026'),
            'invitation_token' => env('SEED_DEMO_INVITATION_TOKEN', 'demo-invitation-cga-connect-0123456789abcdef'),
            // Lien renvoyé depuis : l'ancien jeton est révoqué (« Ce lien n'est plus valide »).
            'revoked_invitation_token' => env('SEED_DEMO_REVOKED_INVITATION_TOKEN', 'demo-invitation-revoquee-0123456789abcdefgh'),
            'otp_decay_minutes' => (int) env('SEED_DEMO_OTP_DECAY_MINUTES', 1),
        ],
    ],

];
