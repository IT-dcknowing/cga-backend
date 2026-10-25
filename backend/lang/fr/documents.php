<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documents officiels (M08)
    |--------------------------------------------------------------------------
    |
    | Titres des documents produits et présentation des valeurs dans les
    | modèles (montants, dates, champs vides, signataire, désignations du
    | reçu, formes juridiques en toutes lettres).
    |
    */

    'title' => ':type — :member',
    'certificate_title' => ':type :year — :member',
    'empty' => '—',
    'amount' => ':amount FCFA',
    'amount_words' => ':words francs CFA',
    'date_time' => ':date à :time',
    'rate' => ':rate %',
    'first_day' => '1er',
    'agreement' => ':number du :date',
    'signatory' => ':name, :title',
    'default_signatory_title' => 'Le Gérant',

    // Désignations des lignes d'un reçu, d'après la somme réglée.
    'receipt_lines' => [
        'entry_fee' => 'Droit d\'adhésion',
        'fees' => 'Honoraires du :start au :end',
        'training' => 'Formation',
    ],

    'payment_not_found' => 'Choisissez un paiement de ce dossier.',

    // Annulation automatique de l'attestation en cours de validité (RG-DOC-08).
    'radiation_cancel_reason' => 'Radiation validée le :date.',

    // Dépôt d'une version de modèle (F-ADM-02).
    'template_unreadable' => 'Ce fichier ne peut pas être lu comme modèle de ce document.',
    'template_version_exists' => 'La version :version de ce modèle existe déjà.',

    // Blocage de l'attestation sur une alerte qui n'est pas en retard (RG-DOC-10).
    'block_alert_missing' => 'Cette alerte n\'est pas en retard pour cet adhérent : choisissez « Décision du gérant ».',

    // Formes juridiques en toutes lettres (attestation) ; une forme absente reste telle quelle.
    'legal_forms' => [
        'SARL' => 'Société à responsabilité limitée',
        'SARLU' => 'Société à responsabilité limitée unipersonnelle',
        'SAS' => 'Société par actions simplifiée',
        'SASU' => 'Société par actions simplifiée unipersonnelle',
        'SA' => 'Société anonyme',
        'SNC' => 'Société en nom collectif',
        'GIE' => 'Groupement d\'intérêt économique',
        'COOP' => 'Société coopérative',
        'EI' => 'Entreprise individuelle',
    ],

];
