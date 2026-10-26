<?php

use App\Domain\Payments\Fne\DgiApiFneCertifier;

return [

    /*
    |--------------------------------------------------------------------------
    | Intégrations externes (02-TRD §10 bis)
    |--------------------------------------------------------------------------
    |
    | Pilote de chaque mode, hôtes autorisés (liste blanche contre la
    | falsification de requêtes, OWASP A10) et délai maximal des appels. Les
    | identifiants restent par organisation, chiffrés en base, jamais ici.
    |
    */

    // Certification FNE (RG-FNE-03) : le mode manuel n'appelle aucun service ; Sell Flow attend son API.
    'fne' => [
        'drivers' => [
            'dgi_api' => DgiApiFneCertifier::class,
        ],
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('FNE_ALLOWED_HOSTS', ''))))),
        'timeout_seconds' => (int) env('FNE_HTTP_TIMEOUT', 30),
    ],

];
