<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rapport trimestriel (PRD M09)
    |--------------------------------------------------------------------------
    */

    'quarters' => [
        1 => 'premier trimestre',
        2 => 'deuxième trimestre',
        3 => 'troisième trimestre',
        4 => 'quatrième trimestre',
    ],
    'quarter_label' => 'T:quarter :year',

    'sections' => [
        'section_1' => 'Fiche technique du CGA',
        'section_2' => 'Capital humain',
        'section_3' => 'Ressources matérielles',
        'section_4' => 'Gestion des adhérents',
        'section_5' => 'États du portefeuille',
        'section_6' => 'Difficultés, suggestions et prévisions',
    ],

    'sheets' => [
        'tee_follow_up' => 'Suivi TEE',
        'rme_follow_up' => 'Suivi RME',
        'new_tee' => 'Nouveaux TEE',
        'new_rme' => 'Nouveaux RME',
        'upgrade_tee_rme' => 'Surclassement TEE → RME',
        'upgrade_rme_rsi' => 'Surclassement RME → RSI',
        'terminations_tee' => 'Résiliations TEE',
        'terminations_rme' => 'Résiliations RME',
        'increased_tee' => 'TEE augmentée',
        'increased_rme' => 'RME augmentée',
        'summary' => 'Résumé',
    ],

    'rme_columns' => [
        'patente' => 'Patente',
        'bic' => 'BIC',
        'tva' => 'TVA',
        'tse' => 'TSE',
        'its' => 'ITS',
        'airsi' => 'AIRSI',
        'igr' => 'IGR',
        'irvm' => 'IRVM',
        'irc' => 'IRC',
        'other' => 'Autres (FDFP)',
        'total' => 'Total',
    ],

    'no_tax_office' => 'CDI non renseigné',

    // Rapport modernisé (étape 5.11) : graphiques, synthèse chiffrée pré-rédigée, sources des données.
    'charts' => [
        'titles' => [
            'regimes' => 'Portefeuille CGA par régime',
            'tax_offices' => 'Adhérents actifs par CDI',
            'trend' => 'Adhérents actifs par trimestre',
            'movements' => 'Nouveaux adhérents et résiliations du trimestre',
            'tee_recovery' => 'Recouvrement de la TEE du trimestre par CDI',
            'rme_natures' => 'Paiements des adhérents RME par nature',
            'closing' => 'Campagne de clôture : SMT et bilans',
        ],
        'members' => 'Adhérents',
        'amount' => 'Montant (F)',
        'active' => 'Adhérents actifs',
        'new' => 'Nouveaux',
        'terminated' => 'Résiliés',
        'due' => 'Exigible',
        'paid' => 'Acquitté',
        'smt' => 'SMT',
        'balance_sheets' => 'Bilans',
        'established' => 'Établis',
        'transmitted' => 'Transmis',
    ],
    'synthesis' => [
        'portfolio' => 'Au :date, le portefeuille CGA compte :total adhérents actifs (:tee TEE, :rme RME), :variation par rapport au trimestre précédent.',
        'variation_up' => 'en hausse de :count',
        'variation_down' => 'en baisse de :count',
        'variation_none' => 'un effectif stable',
        'variation_unknown' => 'sans comparaison possible',
        'movements' => 'Le trimestre enregistre :new nouvelle(s) adhésion(s) et :terminated résiliation(s).',
        'tee' => 'La TEE du trimestre est recouvrée à :rate (:paid acquittés sur :due exigibles).',
        'tee_none' => 'Aucune TEE n\'était exigible sur le trimestre.',
        'rme' => 'Les adhérents RME ont payé :total d\'impôts sur le trimestre.',
        'closing' => 'Campagne de clôture : :smt SMT et :balance_sheets bilans transmis.',
    ],
    'sources' => [
        'text' => 'Chiffres d\'affaires : :revenues. Paiements du trimestre : :payments.',
        'item' => ':count de source « :source »',
        'none' => 'aucune donnée',
        'kinds' => [
            'entry' => 'saisie', 'report' => 'rapport', 'import' => 'import', 'compta_flow' => 'Compta Flow',
            'cga_entry' => 'saisie du CGA', 'member_declaration' => 'déclaration de l\'adhérent',
        ],
    ],
    'cnps' => ['declared' => 'Déclaré', 'not_declared' => 'Non déclaré'],
    'established_transmitted' => ':established établis, :transmitted transmis',
    'years' => '{0} |{1} (:count an)|[2,*] (:count ans)',
    'movements' => ['new' => 'Nouvel adhérent', 'terminated' => 'Résilié'],
    'quarter_not_open' => 'Choisissez le trimestre en cours ou le précédent.',
    'document_title' => 'Rapport trimestriel :label',
    'network_status' => [
        'acknowledged' => 'Accusé reçu',
        'transmitted' => 'Transmis',
        'legally_late' => 'Hors délai réglementaire',
        'late' => 'En retard',
        'not_transmitted' => 'Non transmis',
    ],
    // Contrôle de structure d'une version du classeur officiel (étape 5.10).
    'workbook' => [
        'sheet_count' => 'Le classeur doit compter :expected feuilles (:found trouvées).',
        'missing_sheet' => 'Feuille « :sheet » absente.',
        'missing_block' => 'Feuille « :sheet » : bloc par CDI introuvable (titre ${tax_office_name}, puis ligne TOTAL en colonne A).',
        'missing_summary_total' => 'Feuille « :sheet », tableau :table : ligne TOTAL attendue à partir de la ligne :row.',
    ],

    'slip_title' => 'Bordereau de transmission du rapport :label',
    'period' => 'du :start au :end',

    'anomalies' => [
        'missing_tax_office' => '{1} :count adhérent sans CDI|[2,*] :count adhérents sans CDI',
        'missing_land_parcel' => '{1} :count adhérent TEE sans n° de section et parcelle|[2,*] :count adhérents TEE sans n° de section et parcelle',
        'missing_reference_revenue' => '{1} :count adhérent sans chiffre d\'affaires de référence|[2,*] :count adhérents sans chiffre d\'affaires de référence',
        'unknown_tee_amount' => '{1} :count adhérent TEE dont le montant du trimestre est inconnu|[2,*] :count adhérents TEE dont le montant du trimestre est inconnu',
    ],

];
