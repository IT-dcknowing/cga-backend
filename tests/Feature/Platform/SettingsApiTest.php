<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Platform\Models\Setting;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;

/*
 * Paramètres du réseau et de la plateforme (06-IMPLEMENTATION-PLAN étapes 5.6b et 5.13, RG-RPT-06 à 08,
 * RG-RPT-10, 02-TRD §7.5) : règle « cotisation augmentée », décalages des relances, échéance
 * réglementaire et export de compatibilité modifiables à l'écran, sans développement ; avant / après
 * tracé ; valeurs contrôlées par le registre.
 */

const DEFAULT_INCREASE_RULE = ['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net'];

beforeEach(function () {
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create();
});

it('RG-RPT-08 · le CNCGA lit les paramètres du rapport : libellé, type, valeur, défaut', function () {
    $response = inSpace($this->president)->getJson('/api/v1/network/settings?group=report')->assertOk();

    expect(collect($response->json('data'))->pluck('key')->all())->toBe([
        'report.deadline_rule', 'report.increase_rule', 'report.legacy_workbook_enabled',
        'report.legal_offset_days', 'report.reminder_channels', 'report.reminder_offsets',
    ])->and($response->json('meta.groups'))->toContain('report', 'dues', 'closing')->not->toContain('files', 'otp');
    $rule = collect($response->json('data'))->firstWhere('key', 'report.increase_rule');
    expect($rule)->toMatchArray([
        'group' => 'report', 'group_label' => 'Rapport trimestriel', 'label' => 'Règle « cotisation augmentée »', 'type' => 'array',
        'value' => DEFAULT_INCREASE_RULE, 'default' => DEFAULT_INCREASE_RULE, 'is_default' => true, 'can' => ['update' => true],
    ])->and($rule['help'])->toContain('RG-RPT-08');
});

it('RG-RPT-08 · modifie la règle « augmentée » ; valeur contrôlée par le registre (422) ; avant / après tracé ; retour au défaut', function () {
    $rule = ['basis' => 'revenue', 'min_increase_pct' => 10, 'amount' => 'gross'];

    $response = inSpace($this->president)->putJson('/api/v1/network/settings/report.increase_rule', ['value' => $rule])
        ->assertOk()->assertJsonPath('data.is_default', false);
    expect($response->json('data.value'))->toEqual($rule);
    expect(app(SettingsService::class)->get('report.increase_rule'))->toEqual($rule);
    $audit = AuditLog::query()->where('subject_type', (new Setting)->getMorphClass())->where('event', 'created')->latest('id')->first();
    expect($audit?->user_id)->toBe($this->president->user_id);

    inSpace($this->president)->putJson('/api/v1/network/settings/report.increase_rule', ['value' => ['basis' => 'humeur', 'min_increase_pct' => -1, 'amount' => 'net']])
        ->assertUnprocessable()->assertJsonValidationErrors(['value.basis', 'value.min_increase_pct']);
    inSpace($this->president)->putJson('/api/v1/network/settings/report.increase_rule', [])->assertUnprocessable()->assertJsonValidationErrors('value');

    $reset = inSpace($this->president)->deleteJson('/api/v1/network/settings/report.increase_rule')->assertOk()->assertJsonPath('data.is_default', true);
    expect($reset->json('data.value'))->toEqual(DEFAULT_INCREASE_RULE);
    expect(app(SettingsService::class)->get('report.increase_rule'))->toEqual(DEFAULT_INCREASE_RULE);
});

it('RG-RPT-06 · RG-RPT-07 · RG-RPT-10 · décalages des relances, échéance réglementaire et export de compatibilité', function () {
    inSpace($this->president)->putJson('/api/v1/network/settings/report.reminder_offsets', ['value' => [-10, -3, 7]])->assertOk();
    inSpace($this->president)->putJson('/api/v1/network/settings/report.legal_offset_days', ['value' => 20])->assertOk()->assertJsonPath('data.value', 20);
    inSpace($this->president)->putJson('/api/v1/network/settings/report.legacy_workbook_enabled', ['value' => false])->assertOk()->assertJsonPath('data.value', false);

    $settings = app(SettingsService::class);
    expect($settings->get('report.reminder_offsets'))->toBe([-10, -3, 7])
        ->and($settings->get('report.legal_offset_days'))->toBe(20)
        ->and($settings->get('report.legacy_workbook_enabled'))->toBeFalse();
});

it('l\'administrateur règle les paramètres de la plateforme et du réseau ; le CNCGA ne voit pas ceux de la plateforme', function () {
    $admin = Membership::factory()->role(Role::PlatformAdmin)->create();

    inSpace($admin)->getJson('/api/v1/platform/settings?group=files')->assertOk()->assertJsonPath('data.0.key', 'files.allowed_types');
    inSpace($admin)->putJson('/api/v1/platform/settings/files.max_upload_mb', ['value' => 20])->assertOk()->assertJsonPath('data.value', 20);
    inSpace($admin)->putJson('/api/v1/platform/settings/report.legacy_workbook_enabled', ['value' => false])->assertOk();
    inSpace($admin)->getJson('/api/v1/platform/settings')->assertOk()->assertJsonPath('meta.groups', fn (array $groups): bool => in_array('files', $groups, true) && in_array('report', $groups, true));

    inSpace($this->president)->putJson('/api/v1/network/settings/files.max_upload_mb', ['value' => 50])->assertNotFound();
    inSpace($this->president)->getJson('/api/v1/network/settings?group=files')->assertUnprocessable()->assertJsonValidationErrors('group');
    inSpace($this->president)->putJson('/api/v1/network/settings/settings.version', ['value' => 99])->assertNotFound();
    inSpace($this->president)->deleteJson('/api/v1/network/settings/inconnu.cle')->assertNotFound();
});

it('réservé à qui paramètre les règles du réseau (PRD §3.3) ; chaque espace sur sa route', function (Role $role, int $network, int $platform) {
    $agent = Membership::factory()->role($role)->create();

    inSpace($agent)->getJson('/api/v1/network/settings')->assertStatus($network);
    inSpace($agent)->putJson('/api/v1/network/settings/report.legal_offset_days', ['value' => 12])->assertStatus($network === 200 ? 200 : 403);
    inSpace($agent)->deleteJson('/api/v1/network/settings/report.legal_offset_days')->assertStatus($network === 200 ? 200 : 403);
    inSpace($agent)->getJson('/api/v1/platform/settings')->assertStatus($platform);
})->with([
    'gestionnaire du CNCGA' => [Role::CncgaManager, 200, 403],
    'bureau (consultation)' => [Role::CncgaViewer, 403, 403],
    'chargé des cotisations' => [Role::CncgaDues, 403, 403],
    'gérant d\'un CGA' => [Role::CgaManager, 403, 403],
    'administrateur de la plateforme' => [Role::PlatformAdmin, 403, 200],
]);
