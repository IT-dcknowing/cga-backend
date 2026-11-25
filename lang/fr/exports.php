<?php

/*
|--------------------------------------------------------------------------
| Exports Excel (PRD M17, F-EXP-01)
|--------------------------------------------------------------------------
|
| Noms des feuilles, en-têtes des colonnes, feuille « Informations » qui horodate l'export (RG-SEC-06).
|
*/

return [

    'sheets' => [
        'members' => 'Adhérents',
        'concerns' => 'Préoccupations',
        'network_concerns' => 'Préoccupations des CGA',
        'training_registrations' => 'Inscrits',
        'obligations' => 'Échéances',
        'dues' => 'Cotisations',
        'statistics_summary' => 'Synthèse',
        'statistics_natures' => 'Recettes par nature',
        'statistics_cgas' => 'Par CGA',
        'statistics_regimes' => 'Par régime',
        'statistics_sectors' => 'Par secteur',
        'statistics_directorates' => 'Par DR',
        'information' => 'Informations',
    ],

    'columns' => [
        'members' => [
            'number' => 'N° CGA Connect',
            'legacy_number' => 'Ancien n°',
            'client_number' => 'N° client',
            'company_name' => 'Raison sociale',
            'sigle' => 'Sigle',
            'ncc' => 'NCC',
            'regime' => 'Régime',
            'portfolio' => 'Portefeuille',
            'status' => 'Statut',
            'adhesion_date' => 'Date d\'adhésion',
            'sector' => 'Secteur',
            'commune' => 'Commune',
            'tax_office' => 'CDI',
            'phone' => 'Téléphone',
            'email' => 'E-mail',
            'fees_amount' => 'Honoraires (F CFA)',
            'fees_periodicity' => 'Périodicité des honoraires',
            'referrer' => 'Apporteur',
        ],
        'concerns' => [
            'submitted_at' => 'Déposée le',
            'member' => 'Adhérent',
            'category' => 'Catégorie',
            'subject' => 'Objet',
            'status' => 'Statut',
            'target_days' => 'Délai visé (jours)',
            'first_response_at' => 'Première réponse',
            'resolved_at' => 'Clôturée le',
            'reopen_count' => 'Réouvertures',
        ],
        'network_concerns' => [
            'submitted_at' => 'Déposée le',
            'organization' => 'CGA',
            'category' => 'Catégorie',
            'subject' => 'Objet',
            'status' => 'Statut',
            'target_days' => 'Délai visé (jours)',
            'first_response_at' => 'Première réponse',
            'resolved_at' => 'Clôturée le',
        ],
        'training_registrations' => [
            'training' => 'Formation',
            'starts_at' => 'Session',
            'person' => 'Inscrit',
            'kind' => 'Agent ou adhérent',
            'organization' => 'CGA',
            'company' => 'Entreprise',
            'status' => 'Statut',
            'payment_status' => 'Paiement',
            'paid_on' => 'Payé le',
            'receipt_number' => 'N° de reçu',
            'attendance' => 'Présence',
        ],
        'obligations' => [
            'due_date' => 'Échéance',
            'member' => 'Adhérent',
            'ncc' => 'NCC',
            'label' => 'Obligation',
            'category' => 'Catégorie',
            'kind' => 'Nature',
            'period' => 'Période',
            'amount_due' => 'Montant dû (F CFA)',
            'amount_paid' => 'Payé (F CFA)',
            'balance' => 'Reste dû (F CFA)',
            'status' => 'Statut',
        ],
        'dues' => [
            'year' => 'Année',
            'organization' => 'CGA',
            'agreement_number' => 'N° d\'agrément',
            'amount_due' => 'Montant dû (F CFA)',
            'amount_paid' => 'Payé (F CFA)',
            'balance' => 'Reste dû (F CFA)',
            'deadline' => 'Date butoir',
            'status' => 'Statut',
            'last_payment' => 'Dernier paiement',
        ],
        'statistics' => [
            'indicator' => 'Indicateur',
            'value' => 'Valeur',
            'coverage' => 'Couverture',
            'tax_revenue' => 'Recettes fiscales (F CFA)',
            'member_revenue' => 'Chiffre d\'affaires des adhérents (F CFA)',
            'budget_contribution' => 'Contribution au budget de l\'État',
            'gdp_contribution' => 'Contribution au PIB',
            'nature' => 'Nature',
            'amount' => 'Montant (F CFA)',
            'organization' => 'CGA',
            'members' => 'Adhérents',
            'regime' => 'Régime',
            'sector' => 'Secteur',
            'directorate' => 'Direction régionale',
            'cgas' => 'CGA',
        ],
    ],

    'values' => [
        'agent' => 'Agent',
        'member' => 'Adhérent',
        'unspecified' => 'Non renseigné',
    ],

    'information' => [
        'list' => 'Liste',
        'exported_at' => 'Exporté le',
        'exported_by' => 'Par',
        'space' => 'Espace',
        'period' => 'Période',
        'period_range' => 'Du :from au :to',
        'all_periods' => 'Toutes les périodes',
        'filters' => 'Filtres',
        'no_filters' => 'Aucun',
        'rows' => 'Lignes',
    ],

];
