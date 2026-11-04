<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tentatives et délais des Jobs (02-TRD §7.6, §8.2)
    |--------------------------------------------------------------------------
    |
    | File, nombre d'essais, attente avant chaque nouvel essai (secondes,
    | croissante) et durée maximale d'un essai, lus par chaque Job.
    |
    */

    // Production d'un document : modèle rempli, QR, conversion PDF (file « documents », un worker).
    'generate_document' => [
        'queue' => env('DOCUMENTS_QUEUE', 'documents'),
        'tries' => (int) env('GENERATE_DOCUMENT_TRIES', 3),
        'backoff' => [30, 120],
        // Au-delà du délai de conversion (pdf.timeout_seconds), pour laisser le pilote s'arrêter seul.
        'timeout' => (int) env('GENERATE_DOCUMENT_TIMEOUT', 300),
    ],

    // Certification FNE d'une facture (file « integrations », 02-TRD §8.5) : un essai par Job, les
    // suivants programmés par fne.retry.* ; au-delà du délai des appels HTTP (integrations.fne.timeout_seconds).
    'certify_invoice' => [
        'queue' => env('INTEGRATIONS_QUEUE', 'integrations'),
        'timeout' => (int) env('CERTIFY_INVOICE_TIMEOUT', 120),
    ],

    // Recalcul ciblé des agrégats d'un CGA (02-TRD §9, file « default ») : dédoublonné par CGA pendant
    // unique_seconds (5 minutes) ; verrou dans le magasin de cache lock_store (vide : celui de CACHE_STORE).
    'refresh_stats' => [
        'queue' => env('STATS_QUEUE', 'default'),
        'tries' => (int) env('REFRESH_STATS_TRIES', 2),
        'timeout' => (int) env('REFRESH_STATS_TIMEOUT', 600),
        'unique_seconds' => (int) env('REFRESH_STATS_UNIQUE_SECONDS', 300),
        'lock_store' => env('REFRESH_STATS_LOCK_STORE'),
    ],

];
