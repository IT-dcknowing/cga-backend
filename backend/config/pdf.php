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

    'libreoffice' => [
        'binary' => env('LIBREOFFICE_BIN', 'soffice'),
    ],

];
