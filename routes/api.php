<?php

use App\Domain\Auth\Http\Controllers\InvitationController;
use App\Domain\Auth\Http\Controllers\MeController;
use App\Domain\Auth\Http\Controllers\PasswordResetController;
use App\Domain\Auth\Http\Controllers\ProfileSecurityController;
use App\Domain\Auth\Http\Controllers\SessionController;
use App\Domain\Documents\Http\Controllers\AttestationBlockController;
use App\Domain\Documents\Http\Controllers\CertificateCampaignController;
use App\Domain\Documents\Http\Controllers\DocumentController;
use App\Domain\Documents\Http\Controllers\DocumentTemplateController;
use App\Domain\Documents\Http\Controllers\FileDownloadController;
use App\Domain\Documents\Http\Controllers\MemberDocumentController;
use App\Domain\Documents\Http\Controllers\MemberSpaceDocumentController;
use App\Domain\Documents\Http\Controllers\VerificationController;
use App\Domain\Imports\Http\Controllers\ImportController;
use App\Domain\Imports\Http\Controllers\MemberImportController;
use App\Domain\Members\Http\Controllers\MemberAccessController;
use App\Domain\Members\Http\Controllers\MemberController;
use App\Domain\Members\Http\Controllers\MemberHistoryController;
use App\Domain\Members\Http\Controllers\MemberHomeController;
use App\Domain\Members\Http\Controllers\MemberTransferController;
use App\Domain\Members\Http\Controllers\PieceController;
use App\Domain\Members\Http\Controllers\PortfolioController;
use App\Domain\Members\Http\Controllers\RadiationController;
use App\Domain\Members\Http\Controllers\ReferrerController;
use App\Domain\Members\Http\Controllers\RegimeController;
use App\Domain\Organizations\Http\Controllers\CgaProfileController;
use App\Domain\Organizations\Http\Controllers\OrganizationController;
use App\Domain\Organizations\Http\Controllers\OrganizationStatusController;
use App\Domain\Payments\Http\Controllers\FeeScheduleController;
use App\Domain\Payments\Http\Controllers\InvoiceController;
use App\Domain\Payments\Http\Controllers\InvoicingProviderController;
use App\Domain\Payments\Http\Controllers\MemberPaymentController;
use App\Domain\Payments\Http\Controllers\MemberSpacePaymentController;
use App\Domain\Payments\Http\Controllers\PaymentController;
use App\Domain\Platform\Http\Controllers\HealthController;
use App\Domain\Referentials\Http\Controllers\CommuneController;
use App\Domain\Referentials\Http\Controllers\HolidayController;
use App\Domain\Referentials\Http\Controllers\MergeController;
use App\Domain\Referentials\Http\Controllers\NationalReferenceController;
use App\Domain\Referentials\Http\Controllers\ReferenceDataController;
use App\Domain\Referentials\Http\Controllers\RegionalDirectorateController;
use App\Domain\Referentials\Http\Controllers\SectorController;
use App\Domain\Referentials\Http\Controllers\SectorProposalController;
use App\Domain\Referentials\Http\Controllers\TaxOfficeController;
use App\Domain\Users\Http\Controllers\AgentController;
use App\Domain\Users\Http\Controllers\RolePermissionController;
use App\Domain\Users\Http\Controllers\UserPermissionController;
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

    // Import d'un portefeuille par le gérant du CGA (F-IMP-02) : modèle, analyse (aperçu, sans rien écrire), dépôt
    // (202, traité en file « imports »), avancement et rapport.
    Route::middleware(['auth', 'tenant'])->prefix('cga/imports')->name('cga.imports.')->group(function (): void {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::get('members/template', [MemberImportController::class, 'template'])->name('members.template');
        Route::post('members/preview', [MemberImportController::class, 'preview'])->name('members.preview');
        Route::post('members', [MemberImportController::class, 'store'])->name('members.store');
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

    // Habilitations par utilisateur, le « nuage » (RG-ROLE-07) : agents du centre par le gérant, utilisateurs du
    // CNCGA par l'administrateur de la plateforme. La modification remplace la liste des droits : PUT.
    Route::middleware(['auth', 'tenant'])->prefix('cga/users/{agent}/permissions')->name('cga.users.permissions.')->group(function (): void {
        Route::get('/', [UserPermissionController::class, 'show'])->name('show');
        Route::put('/', [UserPermissionController::class, 'update'])->name('update');
        Route::post('reset', [UserPermissionController::class, 'reset'])->name('reset');
    });
    Route::middleware(['auth', 'tenant'])->prefix('platform/network-users')->name('platform.network-users.')->group(function (): void {
        Route::get('/', [UserPermissionController::class, 'networkUsers'])->name('index');
        Route::get('{agent}/permissions', [UserPermissionController::class, 'show'])->name('permissions.show');
        Route::put('{agent}/permissions', [UserPermissionController::class, 'update'])->name('permissions.update');
        Route::post('{agent}/permissions/reset', [UserPermissionController::class, 'reset'])->name('permissions.reset');
    });

    // Dossiers des adhérents du centre (F-ADH-01 à 10, F-PJ-01) : fiche, régime, radiation en deux temps, pièces,
    // accès en ligne, réimport depuis un autre CGA. La modification remplace la fiche : PUT (multipart avec
    // _method=PUT quand elle porte un justificatif, RG-ADH-06b).
    Route::middleware(['auth', 'tenant'])->prefix('cga/members')->name('cga.members.')->group(function (): void {
        Route::get('/', [MemberController::class, 'index'])->name('index');
        Route::post('/', [MemberController::class, 'store'])->name('store');
        Route::get('ncc-check', [MemberController::class, 'nccCheck'])->name('ncc-check');
        Route::get('radiation-options', [RadiationController::class, 'options'])->name('radiation-options');
        Route::post('transfers', [MemberTransferController::class, 'store'])->name('transfers.store');
        Route::get('{member}', [MemberController::class, 'show'])->name('show');
        Route::put('{member}', [MemberController::class, 'update'])->name('update');
        Route::delete('{member}', [MemberController::class, 'destroy'])->name('destroy');
        Route::post('{member}/validate', [MemberController::class, 'validate'])->name('validate');
        Route::get('{member}/history', [MemberHistoryController::class, 'index'])->name('history.index');
        Route::get('{member}/regimes', [RegimeController::class, 'index'])->name('regimes.index');
        Route::post('{member}/regimes', [RegimeController::class, 'store'])->name('regimes.store');
        Route::post('{member}/radiation', [RadiationController::class, 'store'])->name('radiation.store');
        Route::post('{member}/radiation/validate', [RadiationController::class, 'validate'])->name('radiation.validate');
        Route::post('{member}/radiation/cancel', [RadiationController::class, 'cancel'])->name('radiation.cancel');
        Route::post('{member}/portfolio/cga', [PortfolioController::class, 'moveToCga'])->name('portfolio.cga');
        Route::get('{member}/charges', [MemberPaymentController::class, 'charges'])->name('charges.index');
        Route::get('{member}/payments', [MemberPaymentController::class, 'index'])->name('payments.index');
        Route::post('{member}/payments', [MemberPaymentController::class, 'store'])->name('payments.store');
        Route::get('{member}/documents', [MemberDocumentController::class, 'index'])->name('documents.index');
        Route::post('{member}/documents', [MemberDocumentController::class, 'store'])->name('documents.store');
        Route::post('{member}/documents/upload', [MemberDocumentController::class, 'upload'])->name('documents.upload');
        Route::get('{member}/attestation-blocks', [AttestationBlockController::class, 'index'])->name('attestation-blocks.index');
        Route::post('{member}/attestation-blocks', [AttestationBlockController::class, 'store'])->name('attestation-blocks.store');
        Route::post('{member}/attestation-blocks/release', [AttestationBlockController::class, 'release'])->name('attestation-blocks.release');
        Route::get('{member}/pieces', [PieceController::class, 'index'])->name('pieces.index');
        Route::post('{member}/pieces/{pieceType}', [PieceController::class, 'store'])->name('pieces.store');
        Route::get('{member}/accesses', [MemberAccessController::class, 'index'])->name('accesses.index');
        Route::post('{member}/accesses', [MemberAccessController::class, 'store'])->name('accesses.store');
        Route::scopeBindings()->group(function (): void {
            Route::post('{member}/accesses/{access}/resend', [MemberAccessController::class, 'resend'])->name('accesses.resend');
            Route::post('{member}/accesses/{access}/disable', [MemberAccessController::class, 'disable'])->name('accesses.disable');
            Route::post('{member}/accesses/{access}/enable', [MemberAccessController::class, 'enable'])->name('accesses.enable');
            Route::post('{member}/payments/{payment}/confirm', [MemberPaymentController::class, 'confirm'])->name('payments.confirm');
            Route::post('{member}/payments/{payment}/reject', [MemberPaymentController::class, 'reject'])->name('payments.reject');
        });
    });

    // Barème du CGA (F-PAY-01) et paiements du centre, dont les déclarations des adhérents à examiner (F-PAY-03).
    Route::middleware(['auth', 'tenant'])->prefix('cga')->name('cga.')->group(function (): void {
        // Secteur proposé à l'inscription d'un adhérent (RG-REF-01).
        Route::post('sectors', [SectorProposalController::class, 'store'])->name('sectors.store');
        Route::get('fee-schedule', [FeeScheduleController::class, 'show'])->name('fee-schedule.show');
        Route::put('fee-schedule', [FeeScheduleController::class, 'update'])->name('fee-schedule.update');
        // Facturation FNE du centre (F-ADM-07, RG-FNE-03).
        Route::get('invoicing-provider', [InvoicingProviderController::class, 'show'])->name('invoicing-provider.show');
        Route::put('invoicing-provider', [InvoicingProviderController::class, 'update'])->name('invoicing-provider.update');
        Route::post('invoicing-provider/test', [InvoicingProviderController::class, 'test'])->name('invoicing-provider.test');
        // Factures et avoirs certifiés FNE (F-PAY-05, F-PAY-06, RG-FNE-01 à 04).
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/manual-certification', [InvoiceController::class, 'certify'])->name('invoices.certify');
        Route::post('invoices/{invoice}/resubmit', [InvoiceController::class, 'resubmit'])->name('invoices.resubmit');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->name('invoices.credit-note');
        Route::post('invoices/{invoice}/deliver', [InvoiceController::class, 'deliver'])->name('invoices.deliver');
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    });

    // Documents du centre (M08) : état de la production (asynchrone, RG-DOC-05), liens signés, relance d'un
    // échec, régénération ou duplicata (RG-ADH-07, RG-DOC-06), annulation (F-DOC-03).
    Route::middleware(['auth', 'tenant'])->prefix('cga/documents')->name('cga.documents.')->group(function (): void {
        Route::get('{document}', [DocumentController::class, 'show'])->name('show');
        Route::post('{document}/retry', [DocumentController::class, 'retry'])->name('retry');
        Route::post('{document}/regenerate', [DocumentController::class, 'regenerate'])->name('regenerate');
        Route::post('{document}/cancel', [DocumentController::class, 'cancel'])->name('cancel');
    });

    // Modèles de documents du réseau (F-ADM-02, RG-DATA-03) : versions, dépôt avec contrôle des variables,
    // aperçu avec des données de test, activation après validation du CNCGA ; administrateur de la plateforme.
    Route::middleware(['auth', 'tenant'])->prefix('platform/document-templates')->name('platform.document-templates.')->group(function (): void {
        Route::get('/', [DocumentTemplateController::class, 'index'])->name('index');
        Route::post('/', [DocumentTemplateController::class, 'store'])->name('store');
        Route::post('{template}/preview', [DocumentTemplateController::class, 'preview'])->name('preview');
        Route::post('{template}/activate', [DocumentTemplateController::class, 'activate'])->name('activate');
    });

    // Campagne annuelle d'attestations du centre (RG-DOC-06) : aperçu de l'année, lancement en tâche de fond.
    Route::middleware(['auth', 'tenant'])->prefix('cga/certificate-campaign')->name('cga.certificate-campaign.')->group(function (): void {
        Route::get('/', [CertificateCampaignController::class, 'show'])->name('show');
        Route::post('/', [CertificateCampaignController::class, 'store'])->name('store');
    });

    // Espace de l'adhérent : ses honoraires et la déclaration d'un paiement (F-PAY-03), ses documents en PDF (RG-DOC-04).
    Route::middleware(['auth', 'tenant'])->prefix('member')->name('member.')->group(function (): void {
        Route::get('home', [MemberHomeController::class, 'show'])->name('home');
        Route::get('documents', [MemberSpaceDocumentController::class, 'index'])->name('documents.index');
        Route::get('charges', [MemberSpacePaymentController::class, 'charges'])->name('charges.index');
        Route::get('payments', [MemberSpacePaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [MemberSpacePaymentController::class, 'store'])->name('payments.store');
    });

    // Apporteurs d'affaires du centre (sous-portefeuille « Accessoires », RG-PTF-06).
    Route::middleware(['auth', 'tenant'])->prefix('cga/referrers')->name('cga.referrers.')->group(function (): void {
        Route::get('/', [ReferrerController::class, 'index'])->name('index');
        Route::post('/', [ReferrerController::class, 'store'])->name('store');
        Route::put('{referrer}', [ReferrerController::class, 'update'])->name('update');
        Route::delete('{referrer}', [ReferrerController::class, 'destroy'])->name('destroy');
    });

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

    // Page publique de vérification d'un document par son QR code (F-DOC-04, 02-TRD §6.6), sans compte ;
    // nombre de consultations par minute limité par VerifyDocument (rate_limit.verification). Un code qui
    // n'a pas la forme d'un code de vérification répond 404 comme un code inconnu.
    Route::get('public/verify/{verificationCode}', [VerificationController::class, 'show'])
        ->where('verificationCode', '[A-Za-z0-9_-]{1,64}')
        ->name('public.verify');
});
