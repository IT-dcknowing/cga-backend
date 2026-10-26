<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Le SPA est servi sur le même domaine que l'API (02-TRD §3) : aucune
    | origine étrangère n'est autorisée par défaut (02-TRD §6.7, A02). Sur le
    | poste local, CORS_ALLOWED_ORIGINS nomme le serveur Vite. Avec les
    | cookies, une origine est toujours nommée, jamais « * ».
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    'supports_credentials' => true,

];
