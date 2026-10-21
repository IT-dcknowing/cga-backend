<?php

use App\Domain\Auth\Http\Controllers\InvitationController;
use App\Domain\Auth\Http\Controllers\MeController;
use App\Domain\Auth\Http\Controllers\PasswordResetController;
use App\Domain\Auth\Http\Controllers\ProfileSecurityController;
use App\Domain\Auth\Http\Controllers\SessionController;
use App\Domain\Documents\Http\Controllers\FileDownloadController;
use App\Domain\Imports\Http\Controllers\ImportController;
use App\Domain\Organizations\Http\Controllers\CgaProfileController;
use App\Domain\Organizations\Http\Controllers\OrganizationController;
use App\Domain\Organizations\Http\Controllers\OrganizationStatusController;
use App\Domain\Platform\Http\Controllers\HealthController;
use App\Domain\Referentials\Http\Controllers\CommuneController;
use App\Domain\Referentials\Http\Controllers\HolidayController;
use App\Domain\Referentials\Http\Controllers\MergeController;
use App\Domain\Referentials\Http\Controllers\NationalReferenceController;
use App\Domain\Referentials\Http\Controllers\ReferenceDataController;
use App\Domain\Referentials\Http\Controllers\RegionalDirectorateController;
use App\Domain\Referentials\Http\Controllers\SectorController;
use App\Domain\Referentials\Http\Controllers\TaxOfficeController;
use App\Domain\Users\Http\Controllers\AgentController;
use App\Domain\Users\Http\Controllers\RolePermissionController;
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

    // Référentiels nationaux (F-REF-01 à 04, 05-BACKEND-SCHEMA §4) : lus dans tout espace, gérés par le CNCGA.
    // La modification remplace toute la fiche : PUT seulement.
    Route::middleware(['auth', 'tenant'])->prefix('referentials')->name('referentials.')->group(function (): void {
        $referential = static function (string $uri, string $controller, string $parameter): void {
            Route::apiResource($uri, $controller)->parameters([$uri => $parameter])->except('update');
            Route::put("{$uri}/{{$parameter}}", [$controller, 'update'])->name("{$uri}.update");
        };

        $referential('regional-directorates', RegionalDirectorateController::class, 'regionalDirectorate');
        $referential('tax-offices', TaxOfficeController::class, 'taxOffice');
        $referential('communes', CommuneController::class, 'commune');
        $referential('sectors', SectorController::class, 'sector');
        $referential('holidays', HolidayController::class, 'holiday');
        $referential('national-references', NationalReferenceController::class, 'nationalReference');

        // Fusion d'une valeur utilisée dans une autre (RG-DATA-05).
        Route::post('regional-directorates/{regionalDirectorate}/merge', [MergeController::class, 'regionalDirectorate'])->name('regional-directorates.merge');
        Route::post('tax-offices/{taxOffice}/merge', [MergeController::class, 'taxOffice'])->name('tax-offices.merge');
        Route::post('communes/{commune}/merge', [MergeController::class, 'commune'])->name('communes.merge');
        Route::post('sectors/{sector}/merge', [MergeController::class, 'sector'])->name('sectors.merge');
    });

    // Données de référence remplaçables sans développement (RG-DATA-01, 02) : aperçu puis application d'un fichier.
    Route::middleware(['auth', 'tenant'])->prefix('reference-data')->name('reference-data.')->group(function (): void {
        Route::get('/', [ReferenceDataController::class, 'index'])->name('index');
        Route::get('{dataset}/template', [ReferenceDataController::class, 'template'])->name('template');
        Route::post('{dataset}/preview', [ReferenceDataController::class, 'preview'])->name('preview');
        Route::post('{dataset}/apply', [ReferenceDataController::class, 'apply'])->name('apply');
        Route::post('{dataset}/validate', [ReferenceDataController::class, 'validate'])->name('validate');
    });

    // Registre des CGA tenu par le CNCGA (F-ORG-01 à 07). La modification remplace la fiche minimale : PUT seulement.
    Route::middleware(['auth', 'tenant'])->prefix('network/organizations')->name('network.organizations.')->group(function (): void {
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        Route::post('/', [OrganizationController::class, 'store'])->name('store');
        Route::get('{organization}', [OrganizationController::class, 'show'])->name('show');
        Route::put('{organization}', [OrganizationController::class, 'update'])->name('update');
        Route::post('{organization}/subscription', [OrganizationStatusController::class, 'subscription'])->name('subscription');
        Route::post('{organization}/suspend', [OrganizationStatusController::class, 'suspend'])->name('suspend');
        Route::post('{organization}/reactivate', [OrganizationStatusController::class, 'reactivate'])->name('reactivate');
        Route::post('{organization}/withdraw-agreement', [OrganizationStatusController::class, 'withdrawAgreement'])->name('withdraw-agreement');
        Route::post('{organization}/manager', [OrganizationController::class, 'inviteManager'])->name('manager');
    });

    // Import du registre des CGA par le CNCGA (F-IMP-01) : modèle, dépôt (202, traité en file « imports »),
    // avancement et rapport d'anomalies ligne par ligne.
    Route::middleware(['auth', 'tenant'])->prefix('network/imports')->name('network.imports.')->group(function (): void {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::get('cgas/template', [ImportController::class, 'cgaTemplate'])->name('cgas.template');
        Route::post('cgas', [ImportController::class, 'storeCgas'])->name('cgas.store');
        Route::get('{importBatch}', [ImportController::class, 'show'])->name('show');
        Route::get('{importBatch}/rows', [ImportController::class, 'rows'])->name('rows');
    });

    // Agents d'une organisation (F-ORG-09, F-ORG-10) : ceux du CGA par son gérant, ceux du CNCGA par la Présidente.
    // {agent} n'est cherché que parmi les agents de l'espace actif.
    foreach (['cga' => 'cga', 'network' => 'network'] as $prefix => $name) {
        Route::middleware(['auth', 'tenant'])->prefix("{$prefix}/users")->name("{$name}.users.")->group(function (): void {
            Route::get('/', [AgentController::class, 'index'])->name('index');
            Route::post('/', [AgentController::class, 'store'])->name('store');
            Route::get('{agent}', [AgentController::class, 'show'])->name('show');
            Route::put('{agent}/roles', [AgentController::class, 'updateRoles'])->name('roles');
            Route::post('{agent}/disable', [AgentController::class, 'disable'])->name('disable');
            Route::post('{agent}/enable', [AgentController::class, 'enable'])->name('enable');
            Route::post('{agent}/invitation', [AgentController::class, 'resendInvitation'])->name('invitation');
        });
    }

    // Droits par rôle, une interface par rôle (F-ADM-05, 02-TRD §6.2) : le réseau par l'administrateur de la
    // plateforme, chaque CGA par son gérant. La modification remplace la liste des droits accordés : PUT.
    Route::middleware(['auth', 'tenant'])->get('permissions/catalog', [RolePermissionController::class, 'catalog'])->name('permissions.catalog');
    foreach (['network', 'cga'] as $level) {
        Route::middleware(['auth', 'tenant'])->prefix("{$level}/roles")->name("{$level}.roles.")->group(function (): void {
            Route::get('/', [RolePermissionController::class, 'roles'])->name('index');
            Route::get('{role}/permissions', [RolePermissionController::class, 'show'])->name('show');
            Route::put('{role}/permissions', [RolePermissionController::class, 'update'])->name('update');
            Route::post('{role}/permissions/reset', [RolePermissionController::class, 'reset'])->name('reset');
            Route::get('{role}/impact', [RolePermissionController::class, 'impact'])->name('impact');
        });
    }

    // Fiche du CGA de l'espace actif, complétée par son gérant (F-ORG-03).
    Route::middleware(['auth', 'tenant'])->prefix('cga/profile')->name('cga.profile.')->group(function (): void {
        Route::get('/', [CgaProfileController::class, 'show'])->name('show');
        Route::put('/', [CgaProfileController::class, 'update'])->name('update');
        Route::post('images/{kind}', [CgaProfileController::class, 'storeImage'])->name('images.store');
    });

    // Téléchargement par lien temporaire signé, délivré après le contrôle des droits (02-TRD §6.4).
    Route::get('files/{file}/download', FileDownloadController::class)
        ->middleware('signed')
        ->name(StorageService::DOWNLOAD_ROUTE);
});
