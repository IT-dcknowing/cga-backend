<?php

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\Setting;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * Seeders du socle (06-IMPLEMENTATION-PLAN étape 0.6) : rejouables sans doublon.
 */

/**
 * Paramètres réseau et plateforme du registre, ceux que SettingsSeeder enregistre.
 */
beforeEach(function () {
    // Les modèles de documents chargés par TemplateSeeder sont rangés par StorageService.
    Storage::fake();
});

function seededSettingsCount(): int
{
    return collect(app(SettingsService::class)->definitions())
        ->filter(fn (array $definition): bool => array_intersect((array) $definition['level'], ['platform', 'network']) !== [])
        ->count();
}

it('crée l\'organisation plateforme et le CNCGA, en données provisoires', function () {
    $this->seed(PlatformSeeder::class);

    $platform = Organization::query()->where('type', OrganizationType::Platform)->sole();
    $cncga = Organization::query()->where('type', OrganizationType::Cncga)->sole();

    expect($platform->name)->toBe('CGA Connect')
        ->and($cncga->name)->toBe('Collectif National des Centres de Gestion Agréés')
        ->and($cncga->sigle)->toBe('CNCGA')
        ->and($cncga->data_status)->toBe(DataStatus::Provisional);
});

it('invite le premier administrateur de la plateforme quand son téléphone est configuré', function () {
    config(['cga.seed.platform_admin.phone' => '+2250700000099', 'cga.seed.platform_admin.email' => 'admin@cga-connect.ci']);

    $this->seed(PlatformSeeder::class);

    $admin = User::query()->where('phone', '+2250700000099')->sole();
    $membership = $admin->memberships()->sole();
    expect($admin->status)->toBe(UserStatus::Invited)
        ->and($admin->password)->toBeNull()
        ->and($membership->role)->toBe(Role::PlatformAdmin)
        ->and($membership->status)->toBe(MembershipStatus::Invited)
        ->and($membership->organization->type)->toBe(OrganizationType::Platform);
});

it('ne crée aucun administrateur sans téléphone configuré', function () {
    config(['cga.seed.platform_admin.phone' => null]);

    $this->seed(PlatformSeeder::class);

    expect(User::query()->count())->toBe(0);
});

it('peut être rejoué sans rien créer deux fois', function () {
    config(['cga.seed.platform_admin.phone' => '+2250700000099']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Organization::query()->count())->toBe(2)
        ->and(User::query()->count())->toBe(1)
        ->and(Membership::query()->count())->toBe(1)
        ->and(DocumentTemplate::query()->count())->toBe(count(config('documents.templates')))
        ->and(Setting::query()->count())->toBe(seededSettingsCount());
});

it('enregistre la valeur par défaut des paramètres réseau et plateforme, sans remplacer une valeur modifiée', function () {
    $this->seed(SettingsSeeder::class);
    $setting = Setting::query()->whereNull('organization_id')->where('key', 'files.disk_alert_percent')->sole();
    expect($setting->value)->toBe(80);

    $setting->value = 90;
    $setting->save();
    $this->seed(SettingsSeeder::class);

    expect($setting->fresh()->value)->toBe(90);
});

it('n\'enregistre pas les paramètres propres à un CGA', function () {
    config(['cga.settings.essai' => ['propre' => ['type' => 'integer', 'level' => 'cga', 'default' => 1]]]);

    $this->seed(SettingsSeeder::class);

    expect(Setting::query()->where('key', 'essai.propre')->exists())->toBeFalse();
});
