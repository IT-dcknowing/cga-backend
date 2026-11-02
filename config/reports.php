<?php

/*
|--------------------------------------------------------------------------
| Rapport trimestriel (PRD M09, 02-TRD §8.3 et §8.4)
|--------------------------------------------------------------------------
|
| Structure du rapport, lue par le calcul des états et la complétude. Les
| libellés sont dans lang/fr/report.php ; les paramètres modifiables à
| l'écran (règle « augmentée », relances, échéances) sont dans le registre
| des paramètres (config/cga.php, groupe report).
|
*/

return [

    // Colonnes de la feuille « Suivi RME » (PRD M09) : natures d'impôt des paiements confirmés du
    // trimestre regroupées par colonne ; « Autres » reprend le FDFP et les natures sans colonne propre.
    'rme_columns' => [
        'patente' => ['patente'],
        'bic' => ['bic'],
        'tva' => ['tva'],
        'tse' => ['tse'],
        'its' => ['its'],
        'airsi' => ['airsi'],
        'igr' => ['igr'],
        'irvm' => ['irvm'],
        'irc' => ['irc'],
        'other' => ['fdfp', 'rme', 'other'],
    ],

    // Nature d'impôt de la cotisation propre à chaque régime du portefeuille CGA : TEE et impôt des
    // micro-entreprises (feuilles « augmentée » et surclassements).
    'regime_tax_nature' => [
        'TEE' => 'tee',
        'RME' => 'rme',
    ],

    // Numéro des documents d'un rapport ({ABR} : abréviation du centre, {YYYY} : année, {Q} : trimestre) ;
    // chaque génération en est une nouvelle version (RG-DOC-03).
    'document_number' => '{ABR}-RT-{YYYY}-T{Q}',

    // Durée (jours) pendant laquelle une relance ou une alerte d'échéance déjà envoyée n'est pas renvoyée
    // (idempotence de reminders:reports, même pour un centre sans rapport préparé).
    'reminder_memory_days' => 90,

    // Années de chiffre d'affaires et de cotisations reprises dans les feuilles de surclassement
    // (N-3 à N-1).
    'upgrade_history_years' => 3,

    // Champs saisis des sections 4 et 6 (F-RPT-02, contenu jsonb du rapport) : type (text, integer) et
    // obligation pour la complétude (RG-RPT-02 ; un texte peut valoir « Néant »). Les nombres de SMT et
    // de bilans sont proposés depuis la campagne de clôture (RG-OBL-12).
    'content' => [
        'section_4' => [
            'trainings' => ['type' => 'text', 'required' => true],
            'assistance_nature' => ['type' => 'text', 'required' => true],
            'assistance_beneficiaries' => ['type' => 'text', 'required' => true],
            'assistance_frequency' => ['type' => 'text', 'required' => true],
            'tools' => ['type' => 'text', 'required' => true],
            'prevention' => ['type' => 'text', 'required' => true],
            'smt_established' => ['type' => 'integer', 'required' => true],
            'smt_transmitted' => ['type' => 'integer', 'required' => true],
            'balance_sheets_established' => ['type' => 'integer', 'required' => true],
            'balance_sheets_transmitted' => ['type' => 'integer', 'required' => true],
            'meetings_dgi' => ['type' => 'text', 'required' => true],
            'meetings_partners' => ['type' => 'text', 'required' => true],
            'promotion' => ['type' => 'text', 'required' => true],
        ],
        'section_6' => [
            'difficulties_internal' => ['type' => 'text', 'required' => true],
            'difficulties_partners' => ['type' => 'text', 'required' => true],
            'suggestions' => ['type' => 'text', 'required' => true],
            'forecasts' => ['type' => 'text', 'required' => true],
            'needs' => ['type' => 'text', 'required' => false],
        ],
    ],

    // Analyse qualitative (RG-RPT-09), complément de la section 6 : la synthèse est pré-rédigée par la
    // plateforme et modifiable ; faits marquants et perspectives sont libres.
    'qualitative_analysis' => [
        'synthesis' => ['required' => true],
        'highlights' => ['required' => false],
        'outlook' => ['required' => false],
    ],

    // Longueur maximale d'un texte saisi du rapport, et plafond d'un nombre saisi.
    'text_max_length' => 5000,
    'integer_max' => 1_000_000,

    // Champs de la fiche technique (section 1) exigés pour la complétude : colonnes de la fiche du centre
    // (cga.*) ou du rapport (report.*).
    'section_1_fields' => [
        'cga.name', 'cga.ncc', 'cga.agreement_number', 'cga.address', 'cga.said', 'cga.history_text',
        'cga.previous_year_revenue', 'report.regional_directorate_label', 'report.report_date',
    ],

    // Impôts du trimestre saisis pour chaque dirigeant actif (section 2).
    'people_taxes' => ['its', 'igr', 'cnps'],

    /*
     * Disposition du classeur officiel de compatibilité (RG-RPT-10, 02-TRD §8.3) : pour chaque feuille
     * d'état, le premier bloc « CDI ${tax_office_name} » du modèle sert de bloc type, reproduit pour
     * chaque CDI ; first_data_offset : première ligne de données après la ligne de titre ; columns :
     * colonne => champ de la ligne (états de PortfolioStates) ; totals : colonnes totalisées sur la
     * ligne TOTAL. Contrôlée au dépôt d'une nouvelle version du modèle (étape 5.10).
     */
    'workbook' => [
        'sheet_count' => 14,
        'cover_sheet' => 'Page de garde',
        'profile_sheet' => 'Fiche tech CGA',
        'summary_sheet' => 'RESUME',
        'blocks' => [
            'tee_follow_up' => [
                'sheet' => 'Suivi TEE', 'first_data_offset' => 5,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'number_and_date', 'G' => 'section_parcel', 'H' => 'reference_revenue',
                    'I' => 'annual_gross', 'J' => 'annual_net', 'K' => 'annual_paid', 'L' => 'annual_months', 'M' => 'annual_balance', 'N' => 'annual_rate',
                    'O' => 'quarter_gross', 'P' => 'quarter_net', 'Q' => 'quarter_paid', 'R' => 'quarter_months', 'S' => 'quarter_balance', 'T' => 'quarter_rate'],
                'totals' => ['I', 'J', 'K', 'L', 'M', 'O', 'P', 'Q', 'R', 'S'],
            ],
            'rme_follow_up' => [
                'sheet' => 'Suivi RME', 'first_data_offset' => 4,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'number_and_date', 'G' => 'section_parcel', 'H' => 'reference_revenue',
                    'I' => 'patente', 'J' => 'bic', 'K' => 'tva', 'L' => 'tse', 'M' => 'its', 'N' => 'airsi', 'O' => 'igr', 'P' => 'irvm', 'Q' => 'irc', 'R' => 'other', 'S' => 'total'],
                'totals' => ['I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'],
            ],
            'new_tee' => [
                'sheet' => 'Nvx TEE', 'first_data_offset' => 3,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'registration_date', 'G' => 'number_and_date', 'H' => 'reference_revenue', 'I' => 'section_parcel'],
                'totals' => ['H'],
            ],
            'new_rme' => [
                'sheet' => 'NvxRME', 'first_data_offset' => 3,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'section_parcel', 'G' => 'number_and_date', 'H' => 'reference_revenue', 'I' => 'registration_date'],
                'totals' => ['H'],
            ],
            'upgrade_tee_rme' => [
                'sheet' => 'Surclassement IS-RME', 'first_data_offset' => 4,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'number_and_date', 'G' => 'section_parcel',
                    'H' => 'revenue_n-3', 'I' => 'revenue_n-2', 'J' => 'revenue_n-1', 'K' => 'dues_n-3', 'L' => 'dues_n-2', 'M' => 'dues_n-1', 'N' => 'dues_n', 'O' => 'reason'],
                'totals' => ['H', 'I', 'J', 'K', 'L', 'M', 'N'],
            ],
            'upgrade_rme_rsi' => [
                'sheet' => 'Surclassement RME-RSI', 'first_data_offset' => 4,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'number_and_date', 'G' => 'section_parcel',
                    'H' => 'revenue_n-3', 'I' => 'revenue_n-2', 'J' => 'revenue_n-1', 'K' => 'reason'],
                'totals' => ['H', 'I', 'J'],
            ],
            'terminations_tee' => [
                'sheet' => 'Résiliation TEE', 'first_data_offset' => 5,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'section_parcel', 'G' => 'number_and_date', 'H' => 'reference_revenue',
                    'J' => 'due', 'K' => 'paid', 'M' => 'balance', 'U' => 'date_and_reason'],
                'totals' => ['J', 'K', 'M'],
            ],
            'terminations_rme' => [
                'sheet' => 'Résiliation RME', 'first_data_offset' => 4,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'section_parcel', 'G' => 'number_and_date', 'H' => 'reference_revenue',
                    'I' => 'patente', 'J' => 'bic', 'K' => 'tva', 'L' => 'tse', 'M' => 'its', 'N' => 'airsi', 'O' => 'igr', 'P' => 'irvm', 'Q' => 'irc', 'R' => 'other', 'S' => 'total', 'T' => 'date_and_reason'],
                'totals' => ['I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'],
            ],
            'increased_tee' => [
                'sheet' => 'TEE augmenté', 'first_data_offset' => 5,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'section_parcel', 'G' => 'number_and_date', 'H' => 'reference_revenue', 'I' => 'previous', 'J' => 'current', 'K' => 'increase_pct'],
                'totals' => ['I', 'J'],
            ],
            'increased_rme' => [
                'sheet' => 'RME augment', 'first_data_offset' => 5,
                'columns' => ['B' => 'name', 'C' => 'ncc', 'D' => 'activity', 'E' => 'location', 'F' => 'section_parcel', 'G' => 'number_and_date', 'H' => 'reference_revenue', 'I' => 'previous', 'J' => 'current', 'K' => 'increase_pct'],
                'totals' => ['I', 'J'],
            ],
        ],
        // Tableaux de la feuille RESUME : première ligne par CDI, colonnes du nom et de la valeur (TEE,
        // RME) ; la ligne TOTAL suit les lignes des CDI.
        'summary_tables' => [
            ['first_row' => 5, 'tee' => ['A', 'B', 'active_tee'], 'rme' => ['D', 'E', 'active_rme']],
            ['first_row' => 12, 'tee' => ['A', 'B', 'terminated_tee'], 'rme' => ['D', 'E', 'terminated_rme']],
            ['first_row' => 21, 'tee' => ['A', 'B', 'new_tee'], 'rme' => ['D', 'E', 'new_rme']],
        ],
    ],

];
