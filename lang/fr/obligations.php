<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Obligations fiscales et sociales (M07)
    |--------------------------------------------------------------------------
    */

    'catalog_version' => 'version du catalogue',
    'catalog_missing' => 'Référentiel des obligations absent (:path) : le catalogue reste vide jusqu\'à son import.',
    'catalog_unreadable' => 'Le référentiel des obligations (:path) n\'est pas un JSON valide.',
    'review_overlap' => 'Une même obligation ne peut pas être à la fois confirmée et retirée.',
    'catalog_file_unreadable' => 'Ce fichier n\'est pas un catalogue lisible : un JSON {"version": …, "obligations": […]} est attendu.',
    'catalog_loaded' => ':count obligation(s) chargée(s), version :version (provisoire).',

];
