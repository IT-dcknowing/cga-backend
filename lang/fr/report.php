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
