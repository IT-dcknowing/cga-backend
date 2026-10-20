<?php

use App\Domain\Auth\Http\Controllers\InvitationController;
use App\Domain\Auth\Http\Controllers\MeController;
use App\Domain\Auth\Http\Controllers\PasswordResetController;
use App\Domain\Auth\Http\Controllers\ProfileSecurityController;
use App\Domain\Auth\Http\Controllers\SessionController;
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
    // Authentification du SPA par cookie de session (02-TRD §6.1, F-AUTH-01 à 03).
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', [SessionController::class, 'store'])->name('login');
        Route::post('logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
        Route::post('password/code', [PasswordResetController::class, 'requestCode'])->name('password.code');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->name('password.reset');
        Route::get('invitations/{invitationToken}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{invitationToken}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    });

    // Session, espace actif et profil de l'utilisateur connecté (02-TRD §6.2, F-AUTH-04 et 05).
    Route::prefix('me')->name('me.')->middleware('auth')->group(function (): void {
        Route::get('/', [MeController::class, 'show'])->name('show');
        Route::patch('/', [MeController::class, 'update'])->name('update');
        Route::post('space', [MeController::class, 'space'])->name('space');
        Route::post('password', [ProfileSecurityController::class, 'password'])->name('password');
        Route::post('phone/code', [ProfileSecurityController::class, 'phoneCode'])->name('phone.code');
        Route::post('phone', [ProfileSecurityController::class, 'phone'])->name('phone');
        Route::patch('preferences', [ProfileSecurityController::class, 'preferences'])->name('preferences');
    });

    // Téléchargement par lien temporaire signé, délivré après le contrôle des droits (02-TRD §6.4).
    Route::get('files/{file}/download', FileDownloadController::class)
        ->middleware('signed')
        ->name(StorageService::DOWNLOAD_ROUTE);
});
