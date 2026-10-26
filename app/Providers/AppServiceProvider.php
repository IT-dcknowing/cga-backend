<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Auth\Support\InvitationToken;
use App\Domain\Documents\Listeners\CancelCertificatesOnRadiation;
use App\Domain\Documents\Listeners\ProduceRadiationNoticeOnValidation;
use App\Domain\Documents\Listeners\ProduceReceiptOnConfirmation;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Policies\AttestationBlockPolicy;
use App\Domain\Documents\Policies\DocumentPolicy;
use App\Domain\Documents\Policies\DocumentTemplatePolicy;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Policies\ImportBatchPolicy;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Policies\MemberPolicy;
use App\Domain\Members\Policies\ReferrerPolicy;
use App\Domain\Notifications\Channels\SmsChannel;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Listeners\LogNotificationActivity;
use App\Domain\Notifications\Providers\LogSmsProvider;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Policies\OrganizationPolicy;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Payments\Policies\InvoicePolicy;
use App\Domain\Payments\Policies\InvoicingProviderPolicy;
use App\Domain\Payments\Policies\PaymentPolicy;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Holiday;
use App\Domain\Referentials\Models\NationalReference;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Referentials\Policies\ReferenceDataPolicy;
use App\Domain\Referentials\Policies\ReferentialPolicy;
use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Domain\Users\Http\Controllers\UserPermissionController;
use App\Domain\Users\Models\RolePermission;
use App\Domain\Users\Models\User;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Domain\Users\Policies\AgentPolicy;
use App\Domain\Users\Policies\RolePermissionPolicy;
use App\Domain\Users\Support\AgentDirectory;
use App\Support\Calendar\BusinessCalendar;
use App\Support\Config\SettingsService;
use App\Support\Files\LibreOfficePdfConverter;
use App\Support\Files\PdfConverter;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\RequestContext;
use App\Support\Logging\SystemActivityListener;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\Rules\NotCommonPassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Services du socle (06-IMPLEMENTATION-PLAN étapes 0.7 et 0.7b). Ceux qui dépendent de la requête
     * ou de l'espace actif sont « scoped » : une instance par requête et par Job.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionCatalog::class);
        $this->app->singleton(PermissionResolver::class);
        $this->app->singleton(SystemActivityListener::class);

        $this->app->scoped(RequestContext::class);
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Tenancy::class);
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(ActivityLogger::class);
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(BusinessCalendar::class);
        $this->app->scoped(AuthSession::class);

        // Pilote SMS « log » jusqu'au branchement des fournisseurs de l'écran « Envois » (lot 6).
        $this->app->bind(SmsProvider::class, LogSmsProvider::class);

        // Conversion PDF : pilote choisi par PDF_CONVERTER (02-TRD §8.2).
        $this->app->bind(PdfConverter::class, static fn (Application $app): PdfConverter => match (config('pdf.driver')) {
            'libreoffice' => $app->make(LibreOfficePdfConverter::class),
            default => throw new InvalidArgumentException('Pilote de conversion PDF inconnu : '.var_export(config('pdf.driver'), true)),
        });
    }

    public function boot(): void
    {
        // Jobs, tâches planifiées et commandes au journal d'activité (02-TRD §6.5).
        Event::subscribe(SystemActivityListener::class);
        Event::subscribe(LogNotificationActivity::class);

        // Documents produits ou annulés par les événements d'autres modules (F-DOC-01, RG-DOC-08).
        Event::listen(PaymentConfirmed::class, ProduceReceiptOnConfirmation::class);
        Event::listen(RadiationValidated::class, CancelCertificatesOnRadiation::class);
        Event::listen(RadiationValidated::class, ProduceRadiationNoticeOnValidation::class);

        // Canal « sms » des notifications (02-TRD §10).
        Notification::extend('sms', static fn (Application $app): SmsChannel => $app->make(SmsChannel::class));

        // Mot de passe : longueur minimale et refus des mots de passe courants (02-TRD §6.1).
        Password::defaults(static fn (): Password => Password::min((int) config('auth.password_min_length'))->rules([new NotCommonPassword]));

        // Référentiels nationaux : une même politique (lecture pour tous, gestion referentials.manage).
        foreach ([RegionalDirectorate::class, TaxOffice::class, Commune::class, Sector::class, Holiday::class, NationalReference::class] as $referential) {
            Gate::policy($referential, ReferentialPolicy::class);
        }

        // Données de référence remplaçables par fichier (RG-DATA-01, 02).
        Gate::policy(ReferenceDataset::class, ReferenceDataPolicy::class);

        // Registre des CGA et fiche du CGA de l'espace actif (PRD §3.3).
        Gate::policy(Organization::class, OrganizationPolicy::class);

        // Dossiers des adhérents (M04).
        Gate::policy(Member::class, MemberPolicy::class);
        Gate::policy(BusinessReferrer::class, ReferrerPolicy::class);
        Gate::policy(MemberPayment::class, PaymentPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(InvoicingProvider::class, InvoicingProviderPolicy::class);

        // Documents officiels produits ou déposés (M08).
        Gate::policy(GeneratedDocument::class, DocumentPolicy::class);
        Gate::policy(AttestationBlock::class, AttestationBlockPolicy::class);
        Gate::policy(DocumentTemplate::class, DocumentTemplatePolicy::class);

        // Imports de fichiers Excel (M17).
        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);

        // Agents d'une organisation (F-ORG-09, F-ORG-10) : un compte n'est trouvé que s'il est agent de
        // l'organisation de l'espace actif (chargé avant la liaison, ResolveTenant).
        Gate::policy(User::class, AgentPolicy::class);

        // Droits par rôle (F-ADM-05).
        Gate::policy(RolePermission::class, RolePermissionPolicy::class);
        // Sous /platform, l'administrateur vise les utilisateurs du CNCGA (RG-ROLE-07).
        Route::bind('agent', static fn (string $id, RouteDefinition $route): User => app(AgentDirectory::class)->find(
            str_starts_with((string) $route->getName(), 'platform.') ? UserPermissionController::network() : app(TenantContext::class)->organization(),
            $id,
        ));

        // Développement (php artisan dev) : un seul processus écoute les files des workers de production
        // (infra/supervisor : default, documents, notifications, imports).
        DevCommands::artisan('queue:listen --queue=default,documents,notifications,imports --tries=1 --timeout=0', 'queue');

        // Lien d'invitation : l'invitation est désignée par son jeton secret (F-AUTH-02).
        Route::bind('invitationToken', static fn (string $token): InvitationToken => InvitationToken::resolve($token));
    }
}
