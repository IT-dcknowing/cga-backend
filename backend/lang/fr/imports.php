<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Imports de fichiers Excel (M17)
    |--------------------------------------------------------------------------
    */

    'template' => [
        'column' => 'Colonne',
        'required' => 'Obligatoire',
        'help' => 'Contenu attendu',
        'yes' => 'Oui',
        'no' => 'Non',
    ],

    'file' => [
        'unreadable' => 'Le fichier ne peut pas être lu : déposez un classeur Excel (.xlsx) rempli d\'après le modèle.',
        'empty' => 'Le fichier est vide : la première feuille doit commencer par la ligne d\'en-tête du modèle.',
        'missing_columns' => 'La ligne d\'en-tête ne suit pas le modèle : colonnes obligatoires absentes (:columns).',
    ],

    'row_failed' => 'Ligne non importée à la suite d\'une erreur technique : réessayez, ou contactez l\'assistance.',

    'report' => [
        'sheet' => 'Rapport',
        'row' => 'Ligne',
        'status' => 'Statut',
        'messages' => 'Motifs ou champs à compléter',
        'summary' => 'Synthèse',
        'total' => 'Lignes lues',
    ],

    // Registre des CGA (F-IMP-01)
    'cgas' => [
        'help' => [
            'name' => 'Dénomination complète du CGA, unique dans le registre (200 caractères au plus).',
            'sigle' => 'Sigle usuel (facultatif).',
            'agreement_number' => 'Numéro d\'agrément, unique. Sans numéro ni date, le CGA est créé « agrément en cours ».',
            'agreement_date' => 'Date de l\'agrément (cellule date, ou jj/mm/aaaa), passée.',
            'city' => 'Ville du siège.',
            'regional_directorate' => 'Code ou nom de la direction régionale des impôts de rattachement, tel qu\'au référentiel.',
            'phone_1' => 'Téléphone de contact, avec ou sans l\'indicatif +225 (cellule au format texte, pour garder le 0 initial).',
            'email' => 'Adresse e-mail de contact.',
        ],
        'required' => ':field : champ obligatoire.',
        'missing' => ':field non renseigné(e).',
        'invalid_phone' => 'Téléphone invalide : « :value ».',
        'invalid_email' => 'E-mail invalide : « :value ».',
        'invalid_date' => 'Date d\'agrément illisible : utilisez une cellule date ou le format jj/mm/aaaa.',
        'future_date' => 'La date d\'agrément est dans le futur.',
        'too_long' => ':field : :max caractères au plus.',
        'duplicate_name' => 'Doublon : un CGA du registre porte déjà cette dénomination.',
        'duplicate_agreement' => 'Doublon : le numéro d\'agrément :number est déjà attribué à un autre CGA.',
        'invalid_row' => 'Ligne invalide.',
        'agreement_incomplete' => 'Agrément incomplet (numéro et date) : CGA créé avec l\'agrément « en cours ».',
        'unknown_directorate' => 'Direction régionale « :value » inconnue du référentiel : à renseigner sur la fiche.',
    ],

];
