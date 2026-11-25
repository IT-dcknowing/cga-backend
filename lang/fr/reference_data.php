<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Données de référence remplaçables par fichier (RG-DATA-01, 02, 05)
    |--------------------------------------------------------------------------
    */

    'help' => [
        'code' => 'Code unique (lettres, chiffres, tiret ou souligné, 20 caractères au plus).',
        'name' => 'Libellé complet.',
        'geo_key' => 'Zone de la carte des statistiques (facultatif).',
        'active' => 'Oui ou Non (Oui si vide).',
        'directorate_code' => 'Code de la direction régionale de rattachement, déjà présente au référentiel.',
        'year' => 'Année sur quatre chiffres.',
        'amount' => 'Montant en francs CFA, sans décimale.',
        'source' => 'Origine du chiffre (loi de finances, INS…).',
    ],

    'required' => ':field : champ obligatoire.',
    'too_long' => ':field : :max caractères au plus.',
    'invalid_code' => 'Code invalide : lettres, chiffres, tiret ou souligné seulement.',
    'invalid_boolean' => ':field : écrire Oui ou Non.',
    'invalid_year' => 'Année invalide : « :value ».',
    'invalid_amount' => ':field : montant entier en francs CFA attendu.',
    'unknown_directorate' => 'Direction régionale « :value » inconnue du référentiel : importez d\'abord les DR.',
    'unknown_directorate_kept' => 'Direction régionale « :value » inconnue du référentiel : laissée vide.',
    'duplicate_in_file' => 'Doublon dans le fichier : même valeur qu\'à la ligne :row.',
    'cga_phone_required' => 'Téléphone obligatoire pour un nouveau CGA.',

];
