<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conversion DOCX → PDF (02-TRD §8.2, T-05)
    |--------------------------------------------------------------------------
    |
    | LibreOffice installé sur le serveur, appelé en mode --headless. Sous
    | Windows, viser soffice.com (programme console), pas soffice.exe, qui
    | n'écrit rien sur la sortie standard.
    |
    */

    // Pilote de conversion : « libreoffice » au démarrage ; « gotenberg » possible plus tard.
    'driver' => env('PDF_CONVERTER', 'libreoffice'),

    // Dossier de travail des conversions, un sous-dossier jetable par conversion.
    'work_path' => storage_path('framework/pdf'),

    'libreoffice' => [
        'binary' => env('LIBREOFFICE_BIN', 'soffice'),
        // Un profil LibreOffice par worker : deux conversions simultanées ne se bloquent pas. Le
        // worker est désigné par LIBREOFFICE_PROFILE_SLOT (numéro de processus Supervisor), sinon
        // par son identifiant de processus.
        'profiles_path' => env('LIBREOFFICE_PROFILES_PATH', storage_path('framework/libreoffice')),
        'profile_slot' => env('LIBREOFFICE_PROFILE_SLOT'),
    ],

];
