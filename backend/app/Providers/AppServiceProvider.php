<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Auth\Support\InvitationToken;
use App\Domain\Notifications\Channels\SmsChannel;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Listeners\LogNotificationActivity;
use App\Domain\Notifications\Providers\LogSmsProvider;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;
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
use Illuminate\Support\Facades\Event;
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

        // Canal « sms » des notifications (02-TRD §10).
        Notification::extend('sms', static fn (Application $app): SmsChannel => $app->make(SmsChannel::class));

        // Mot de passe : longueur minimale et refus des mots de passe courants (02-TRD §6.1).
        Password::defaults(static fn (): Password => Password::min((int) config('auth.password_min_length'))->rules([new NotCommonPassword]));

        // Lien d'invitation : l'invitation est désignée par son jeton secret (F-AUTH-02).
        Route::bind('invitationToken', static fn (string $token): InvitationToken => InvitationToken::resolve($token));
    }
}
