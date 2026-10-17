<?php

use App\Domain\Platform\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Point de santé surveillé chaque minute (02-TRD §12.3), sans authentification. Sa limitation
 * de débit est faite par Nginx (infra/nginx) : elle ne doit dépendre ni de la base ni du cache,
 * que ce point contrôle justement.
 */
Route::get('health', HealthController::class)->name('health');
