<?php

/*
|--------------------------------------------------------------------------
| Imports de fichiers Excel (PRD M17)
|--------------------------------------------------------------------------
|
| Historique des chiffres d'affaires et des paiements d'impôts (F-IMP-03, étape 5.6) : première année
| acceptée dans les fichiers repris.
|
*/

return [

    'history' => [
        'first_year' => (int) env('IMPORT_HISTORY_FIRST_YEAR', 2000),
    ],

];
