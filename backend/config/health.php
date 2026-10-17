<?php

use App\Support\Health\Checks\DatabaseCheck;
use App\Support\Health\Checks\LibreOfficeCheck;
use App\Support\Health\Checks\QueueCheck;
use App\Support\Health\Checks\StorageCheck;

return [

    /*
    |--------------------------------------------------------------------------
    | Point de santé GET /api/health (02-TRD §12.3)
    |--------------------------------------------------------------------------
    |
    | Contrôles exécutés à chaque appel, dans cet ordre. Le point répond 200 si
    | tous sont bons, 503 sinon.
    |
    */

    'checks' => [
        DatabaseCheck::class,
        QueueCheck::class,
        StorageCheck::class,
        LibreOfficeCheck::class,
    ],

    // Délai maximal de « soffice --version » ; le premier lancement de LibreOffice est lent.
    'libreoffice_timeout_seconds' => (int) env('HEALTH_LIBREOFFICE_TIMEOUT_SECONDS', 15),

];
