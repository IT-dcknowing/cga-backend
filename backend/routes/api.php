<?php

use App\Domain\Documents\Http\Controllers\FileDownloadController;
use App\Domain\Platform\Http\Controllers\HealthController;
use App\Support\Files\StorageService;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
 * Point de santé surveillé chaque minute (02-TRD §12.3), sans authentification. Sa limitation
 * de débit est faite par Nginx (infra/nginx) : elle ne doit dépendre ni de la base ni du cache,
 * que ce point contrôle justement. Pour la même raison, il n'ouvre jamais de session, même appelé
 * depuis le SPA.
 */
Route::get('health', HealthController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->name('health');

Route::prefix('v1')->group(function (): void {
    // Téléchargement par lien temporaire signé, délivré après le contrôle des droits (02-TRD §6.4).
    Route::get('files/{file}/download', FileDownloadController::class)
        ->middleware('signed')
        ->name(StorageService::DOWNLOAD_ROUTE);
});
