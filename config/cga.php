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
            // Validité d'une attestation d'adhésion à compter de son émission (RG-DOC-06).
            'certificate_validity_months' => [
                'type' => 'integer', 'level' => 'network', 'default' => 12, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:36']],
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

        // Cotisation des CGA au CNCGA (PRD M14) : montant de l'année, date butoir (mois-jour), modalité,
        // prorata d'un CGA agréé en cours d'année (RG-COT-01, 02), relances après la date butoir et alerte
        // à la Présidente (RG-COT-05, 06). Niveau réseau, réglé par le CNCGA.
        'dues' => [
            'amount' => [
                'type' => 'integer', 'level' => 'network', 'default' => 750_000, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:0', 'max:1000000000']],
            ],
            'deadline' => [
                'type' => 'string', 'level' => 'network', 'default' => '06-30', 'permission' => 'network_settings.manage',
                'rules' => ['' => ['string', 'date_format:m-d']],
            ],
            'mode' => [
                'type' => 'string', 'level' => 'network', 'default' => 'free', 'permission' => 'network_settings.manage',
                'rules' => ['' => ['string', 'in:free,single,quarterly']],
            ],
            'prorata' => [
                'type' => 'string', 'level' => 'network', 'default' => 'semester', 'permission' => 'network_settings.manage',
                'rules' => ['' => ['string', 'in:semester,none']],
            ],
            'reminder_offsets' => [
                'type' => 'array', 'level' => 'network', 'default' => [30, 60, 90], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array', 'max:10'], '*' => ['integer', 'min:1', 'max:365', 'distinct']],
            ],
            // Taux de TVA des factures de cotisation (étape 6.9), en % ; la cotisation statutaire est hors taxe par défaut.
            'tax_rate' => [
                'type' => 'integer', 'level' => 'network', 'default' => 0, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:0', 'max:100']],
            ],
            'alert_offset' => [
                'type' => 'integer', 'level' => 'network', 'default' => 90, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
        ],

        // Envois groupés (F-COM-03) : au-delà de ce nombre de destinataires, validation du gérant (RG-COM-01).
        'campaign' => [
            'approval_threshold' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 50, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:100000']],
            ],
        ],

        // Quota de SMS inclus par centre et par mois (02-TRD §10) ; les packs achetés s'y ajoutent.
        'sms' => [
            'monthly_quota' => [
                'type' => 'integer', 'level' => ['platform', 'cga'], 'default' => 200, 'permission' => $platform,
                'rules' => ['' => ['integer', 'min:0', 'max:100000']],
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

        // Barème (F-PAY-01) : droit d'adhésion proposé tant que le CGA n'a pas saisi le sien.
        'fees' => [
            // Rappel des honoraires impayés à l'adhérent : le jour du passage en impayé, puis tous les n jours.
            'reminder_every_days' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 30, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
            'default_entry_fee' => [
                'type' => 'integer', 'level' => 'network', 'default' => 25_000, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:0', 'max:100000000']],
            ],
            // Jour d'échéance des honoraires dans le premier mois de la période (F-PAY-04 : le 1er par défaut).
            'due_day' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 1, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:28']],
            ],
            // Une échéance non réglée ce nombre de jours après sa date passe « impayée » (RG-PAY-03).
            'overdue_days' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 30, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
            // Honoraires impayés depuis plus de ce nombre de mois : alerte au gérant, jamais de blocage
            // automatique (RG-PAY-05, CR-04).
            'alert_after_months' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 3, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:24']],
            ],
        ],

        // Adhérent du portefeuille CGA reclassé au régime réel (RG-ADH-08, 09) : alerte au gérant passé ce délai
        // sans radiation, jamais de blocage ni de radiation automatique (CR-04, RG-ADH-18).
        // Pièces attendues d'un dossier : rappel à J+n puis tous les n jours (PRD M13).
        'pieces' => [
            'reminder_days' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 30, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
        ],

        // Échéance de l'agrément d'un CGA : alertes n mois avant (RG-ORG-10).
        'agreements' => [
            'alert_months' => [
                'type' => 'array', 'level' => 'network', 'default' => [6, 3], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array', 'max:6'], '*' => ['integer', 'min:1', 'max:24', 'distinct']],
            ],
        ],

        'rsi' => [
            // Alertes d'un dossier RSI ou RNI resté au portefeuille CGA, n jours après le reclassement.
            'alert_days' => [
                'type' => 'array', 'level' => ['network', 'cga'], 'default' => [60, 90], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array', 'max:6'], '*' => ['integer', 'min:1', 'max:365', 'distinct']],
            ],
            'radiation_deadline_days' => [
                'type' => 'integer', 'level' => 'network', 'default' => 90, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:365']],
            ],
        ],

        // Échéances des obligations confirmées générées chaque nuit sur ce nombre de mois glissants (RG-OBL-04).
        'obligations' => [
            // Rappels d'échéance à l'adhérent : premier et dernier rappel, en jours avant l'échéance (PRD M13).
            'reminder_days' => [
                'type' => 'array', 'level' => ['network', 'cga'], 'default' => ['first' => 7, 'last' => 1], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array:first,last'], 'first' => ['required', 'integer', 'min:1', 'max:60'], 'last' => ['required', 'integer', 'min:0', 'max:60']],
            ],
            'horizon_months' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 3, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:24']],
            ],
            // Indicateur « Échéances sous n jours » du planning du portefeuille (F-OBL-06).
            'upcoming_days' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 15, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:90']],
            ],
        ],

        // Campagne de clôture (F-OBL-08, RG-OBL-10, 11) : dates internes du réseau ajustables par chaque CGA
        // (mois-jour de l'année N+1), date de dépôt légal réglée par le CNCGA seul, rappels de pièces.
        'closing' => [
            'calendar' => [
                'type' => 'array', 'level' => ['network', 'cga'], 'permission' => 'network_settings.manage',
                'default' => ['pieces' => '01-30', 'regularisation' => '02-28', 'finalisation' => '03-31'],
                'rules' => ['' => ['array:pieces,regularisation,finalisation'], 'pieces' => ['required', 'date_format:m-d'], 'regularisation' => ['required', 'date_format:m-d'], 'finalisation' => ['required', 'date_format:m-d']],
            ],
            'filing_date' => [
                'type' => 'string', 'level' => 'network', 'default' => '05-30', 'permission' => 'network_settings.manage',
                'rules' => ['' => ['string', 'date_format:m-d']],
            ],
            'reminder_offsets' => [
                'type' => 'array', 'level' => ['network', 'cga'], 'default' => [-15, -5], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array', 'max:10'], '*' => ['integer', 'min:-90', 'max:-1']],
            ],
            'late_reminder_every_days' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 7, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:1', 'max:60']],
            ],
        ],

        // Rapport trimestriel (PRD M09) : règle « cotisation augmentée » du réseau (RG-RPT-08) — base de
        // comparaison (montant annuel dû N / N-1, montant dû du trimestre / même trimestre N-1, chiffre
        // d'affaires de référence N / N-1), hausse minimale en %, montant après ou avant abattement.
        'report' => [
            'increase_rule' => [
                'type' => 'array', 'level' => 'network', 'permission' => 'network_settings.manage',
                'default' => ['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net'],
                'rules' => [
                    '' => ['array:basis,min_increase_pct,amount'],
                    'basis' => ['required', 'in:annual_due,quarter_due,revenue'],
                    'min_increase_pct' => ['required', 'numeric', 'min:0', 'max:1000'],
                    'amount' => ['required', 'in:net,gross'],
                ],
            ],
            // Date limite J (RG-RPT-06) : n mois après la fin du trimestre, le jour indiqué (0 : dernier jour
            // du mois) ; par défaut, fin du mois qui suit le trimestre.
            'deadline_rule' => [
                'type' => 'array', 'level' => 'network', 'permission' => 'network_settings.manage',
                'default' => ['months_after' => 1, 'day' => 0],
                'rules' => [
                    '' => ['array:months_after,day'],
                    'months_after' => ['required', 'integer', 'min:0', 'max:6'],
                    'day' => ['required', 'integer', 'min:0', 'max:31'],
                ],
            ],
            // Export du classeur officiel de 14 feuilles avec chaque rapport généré (RG-RPT-10) : activé tant
            // que le SP-CGA n'a pas confirmé l'acceptation du seul rapport modernisé.
            'legacy_workbook_enabled' => [
                'type' => 'boolean', 'level' => 'network', 'default' => true, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['boolean']],
            ],
            // Relances au gérant et à l'expert-comptable d'un rapport non transmis : jours avant (négatifs) ou
            // après la date limite J (RG-RPT-06, CR-09) ; canaux de ces relances (moteur de notifications, Lot 6).
            'reminder_offsets' => [
                'type' => 'array', 'level' => 'network', 'default' => [-15, -5, 5], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array', 'max:10'], '*' => ['integer', 'min:-60', 'max:60', 'distinct']],
            ],
            'reminder_channels' => [
                'type' => 'array', 'level' => 'network', 'default' => ['database', 'mail', 'sms'], 'permission' => 'network_settings.manage',
                'rules' => ['' => ['array', 'min:1'], '*' => ['string', 'in:database,mail,sms,whatsapp', 'distinct']],
            ],
            // Échéance réglementaire stricte : J + n jours (RG-RPT-07, CR-09).
            'legal_offset_days' => [
                'type' => 'integer', 'level' => 'network', 'default' => 15, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:0', 'max:90']],
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
            // Reçus des paiements de cotisation émis par le CNCGA (F-COT-03, 04, PRD F-DOC-01 « CNC-AAAA-NNNNN ») :
            // séquence propre par année.
            'dues_receipt' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => 'CNC-{YYYY}-{N:5}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:60', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
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
            // Reçus de paiement : séquence par CGA et par année, sans trou (RG-PAY-02).
            'receipt' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => 'REC-{ABR}-{YYYY}-{N:5}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:60', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
            // Attestations d'adhésion (F-DOC-01) : séquence « attestation » par CGA et par année.
            'certificate' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => 'ATT-{ABR}-{YYYY}-{N:5}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:60', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
            // Courriers de notification de radiation au CDI (F-DOC-01) : séquence « notification_letter ».
            'radiation_notice' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => 'NOT-{ABR}-{YYYY}-{N:5}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:60', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
            // Code d'un secteur proposé par un CGA (RG-REF-01) : séquence « sector_proposal » du centre ; le
            // code produit compte 10 caractères au plus (sectors.code), contrôlé à chaque proposition.
            'sector_proposal' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => '{ABR}{N:4}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:20', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
            // Factures et avoirs (F-PAY-05, F-DOC-01) : séquence « invoice » du centre par année, sans trou.
            'invoice' => [
                'pattern' => [
                    'type' => 'string', 'level' => 'platform', 'default' => 'FAC-{ABR}-{YYYY}-{N:5}', 'permission' => $platform,
                    'rules' => ['' => ['string', 'max:40', 'regex:/\{N(:\d)?\}/']],
                ],
            ],
        ],

        // Factures (F-PAY-05) : taux de TVA ajouté au barème hors taxe dès la création de la somme due
        // (décision du 23/10/2026), réglable par le CNCGA et par chaque CGA.
        'invoices' => [
            'vat_rate' => [
                'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 18, 'permission' => 'network_settings.manage',
                'rules' => ['' => ['integer', 'min:0', 'max:100']],
            ],
        ],

        // Certification FNE (RG-FNE-04, 02-TRD §10 bis.1) : nouveaux essais quand le service est
        // indisponible (nombre, attente croissante en secondes), puis alerte du gérant.
        'fne' => [
            'retry' => [
                'tries' => [
                    'type' => 'integer', 'level' => 'platform', 'default' => 5, 'permission' => $platform,
                    'rules' => ['' => ['integer', 'min:1', 'max:20']],
                ],
                'backoff_seconds' => [
                    'type' => 'array', 'level' => 'platform', 'default' => [300, 900, 3600, 14400], 'permission' => $platform,
                    'rules' => ['' => ['array', 'min:1'], '*' => ['integer', 'min:60', 'max:86400']],
                ],
            ],
        ],

    ],

    // Obligations fiscales et sociales (M07) : version portée par les obligations locales d'un CGA,
    // hors du catalogue national versionné (RG-OBL-01) ; borne de saisie des montants (F CFA).
    'obligations' => [
        'local_catalog_version' => 'local',
        'max_amount' => 100_000_000_000,
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
        // Référentiel des obligations de DC-KNOWING (F-OBL-01, étape 4.1), fourni en JSON : chargé par
        // ObligationCatalogSeeder s'il est présent, sinon le catalogue reste vide jusqu'à son import.
        'obligations' => [
            'catalog_path' => env('SEED_OBLIGATION_CATALOG_PATH') ?: database_path('data/obligation-catalog.json'),
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
