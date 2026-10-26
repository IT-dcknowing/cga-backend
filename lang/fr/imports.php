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

    'members' => [
        'help' => [
            'portfolio' => 'CGA ou Cabinet. Le portefeuille CGA suppose un contrat CGA signé et un régime RME ou TEE.',
            'legacy_number' => 'Numéro d\'adhésion dans l\'ancien système, gardé tel quel (« Ancien n° », recherchable).',
            'status' => 'Actif (par défaut) ou Brouillon. Un dossier actif reçoit son n° CGA Connect ; il est réputé avoir payé son droit d\'adhésion.',
            'adhesion_date' => 'Date d\'adhésion (cellule date, ou jj/mm/aaaa) : elle ordonne la numérotation CGA Connect.',
            'cga_contract_signed_on' => 'Date de signature du contrat CGA (lignes CGA) ; à défaut, la date d\'adhésion.',
            'referrer' => 'Libellé « Accessoire n » ou nom de l\'apporteur du centre (lignes CGA seulement : une ligne Cabinet avec apporteur est rejetée).',
            'company_name' => 'Raison sociale (200 caractères au plus), mise en majuscules.',
            'sigle' => 'Sigle usuel (facultatif).',
            'legal_form' => 'SARL, SARLU, SAS, SASU, SA, SNC, GIE, COOP, EI ou Autre. EI : entreprise individuelle.',
            'ncc' => 'Numéro de compte contribuable : 7 chiffres et une lettre (ex. 1234567A).',
            'rccm' => 'Numéro RCCM (facultatif).',
            'idu' => 'Identifiant unique (facultatif).',
            'regime' => 'RME, TEE, RSI ou RNI (RSI et RNI au portefeuille Cabinet seulement).',
            'main_activity' => 'Activité principale de l\'entreprise.',
            'sector' => 'Code ou nom du secteur d\'activité, tel qu\'au référentiel.',
            'tax_office' => 'Code ou nom du centre des impôts de rattachement, tel qu\'au référentiel.',
            'commune' => 'Nom de la commune, tel qu\'au référentiel (facultatif).',
            'address' => 'Adresse ou localisation.',
            'phone' => 'Téléphone, avec ou sans l\'indicatif +225 (cellule au format texte, pour garder le 0 initial).',
            'whatsapp' => 'Numéro WhatsApp ; à défaut, le téléphone.',
            'email' => 'Adresse e-mail (facultative).',
            'owner_last_name' => 'Nom du dirigeant (obligatoire pour une entreprise individuelle).',
            'owner_first_names' => 'Prénoms du dirigeant (obligatoires pour une entreprise individuelle).',
            'owner_nationality' => 'Nationalité du dirigeant (obligatoire pour une entreprise individuelle).',
            'owner_id_document_type' => 'Type de pièce d\'identité du dirigeant : CNI, passeport… (obligatoire pour une entreprise individuelle).',
            'owner_id_document_number' => 'Numéro de la pièce d\'identité du dirigeant (obligatoire pour une entreprise individuelle).',
            'signatory_name' => 'Nom du signataire du contrat.',
            'signatory_title' => 'Qualité du signataire (gérant, directeur…).',
            'employees_count' => 'Nombre de salariés (facultatif).',
            'fees_amount' => 'Montant des honoraires en FCFA ; à défaut, celui du barème du centre.',
            'fees_periodicity' => 'Mensuelle, Trimestrielle ou Annuelle ; à défaut, celle du barème.',
        ],
        'required' => ':field : champ obligatoire.',
        'missing' => ':field non renseigné(e).',
        'invalid_value' => ':field : valeur « :value » non reconnue.',
        'invalid_ncc' => 'NCC mal formé : « :value » (7 chiffres et une lettre).',
        'regime_not_cga' => 'Régime :regime : le portefeuille CGA n\'accueille que le RME et le TEE.',
        'referrer_cabinet' => 'Un apporteur n\'est rattaché qu\'à un dossier du portefeuille CGA.',
        'invalid_date' => ':field illisible : utilisez une cellule date ou le format jj/mm/aaaa.',
        'future_date' => ':field est dans le futur.',
        'too_long' => ':field : :max caractères au plus.',
        'duplicate' => 'Doublon : le NCC :ncc a déjà un dossier ouvert dans le centre.',
        'duplicate_in_file' => 'Doublon : le NCC :ncc figure déjà à la ligne :row du fichier.',
        'unknown' => ':field « :value » inconnu(e) du référentiel : à renseigner sur la fiche.',
        'draft_status' => 'Statut « Brouillon » dans le fichier : adhésion à valider.',
        'invalid_row' => 'Ligne invalide.',
        'numbered' => 'N° :number (ancien n° :legacy).',
        'start_number_taken' => 'Ce numéro est déjà attribué : la séquence reprend au plus tôt à :number.',
    ],

];
