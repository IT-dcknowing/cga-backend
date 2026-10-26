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

    // Délai maximal de « soffice --version » ; le premier lancement de LibreOffice est lent. Il doit
    // rester nettement sous la limite d'une requête PHP (max_execution_time, request_terminate_timeout),
    // sinon c'est la requête entière qui est interrompue (500) au lieu d'un contrôle en défaut.
    'libreoffice_timeout_seconds' => (int) env('HEALTH_LIBREOFFICE_TIMEOUT_SECONDS', 15),

];
