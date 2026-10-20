<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\Permissions\PermissionCatalog;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Calendar\BusinessCalendar;
use App\Support\Config\SettingsService;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\RequestContext;
use App\Support\Logging\SystemActivityListener;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
        // Jobs, tâches planifiées et commandes au journal d'activité (02-TRD §6.5).
        Event::subscribe(SystemActivityListener::class);
    }
}
