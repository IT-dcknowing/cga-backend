<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Platform\Models\Setting;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Validation\ValidationException;

/*
 * Registre des paramètres (02-TRD §7.5, §7.6) : clé déclarée, valeur validée, CGA → réseau → défaut,
 * modification journalisée et versionnée.
 */

function settings(): SettingsService
{
    return app(SettingsService::class);
}

/**
 * Paramètre de test réglable par le réseau et par chaque CGA.
 */
function declareMixedSetting(): void
{
    config(['cga.settings.essai.mixte' => [
        'type' => 'integer', 'level' => ['network', 'cga'], 'default' => 30, 'permission' => 'network_settings.manage',
        'rules' => ['' => ['integer', 'min:1', 'max:90']],
    ]]);
}

it('renvoie la valeur par défaut déclarée dans le registre', function () {
    expect(settings()->get('files.disk_alert_percent'))->toBe(80)
        ->and(settings()->default('pagination.default'))->toBe(25)
        ->and(settings()->get('messaging.send_window'))->toBe(['from' => '07:00', 'to' => '20:00']);
});

it('refuse une clé absente du registre, ou un groupe de clés', function (string $key) {
    settings()->get($key);
})->throws(InvalidArgumentException::class)->with(['files.unknown_key', 'files', 'pagination']);

it('distingue un paramètre nommé « default » d\'un groupe', function () {
    $keys = array_keys(settings()->definitions());

    expect($keys)->toContain('pagination.default', 'pagination.max', 'numbering.membership.pattern')
        ->not->toContain('pagination');
});

it('enregistre une valeur du réseau, qui remplace la valeur par défaut', function () {
    settings()->set('files.disk_alert_percent', 90);

    expect(settings()->get('files.disk_alert_percent'))->toBe(90)
        ->and(Setting::query()->whereNull('organization_id')->where('key', 'files.disk_alert_percent')->sole()->value)->toBe(90);
});

it('lit la valeur du CGA, sinon celle du réseau, sinon la valeur par défaut', function () {
    declareMixedSetting();
    $cga = Organization::factory()->create();
    $other = Organization::factory()->create();

    expect(settings()->get('essai.mixte', $cga->id))->toBe(30);

    settings()->set('essai.mixte', 45);
    expect(settings()->get('essai.mixte', $cga->id))->toBe(45);

    settings()->set('essai.mixte', 60, $cga->id);
    expect(settings()->get('essai.mixte', $cga->id))->toBe(60)
        ->and(settings()->get('essai.mixte', $other->id))->toBe(45)
        ->and(settings()->get('essai.mixte'))->toBe(45);
});

it('ignore la valeur d\'un CGA pour un paramètre réservé à la plateforme', function () {
    $cga = Organization::factory()->create();

    settings()->set('files.disk_alert_percent', 85);

    expect(settings()->get('files.disk_alert_percent', $cga->id))->toBe(85);
});

it('refuse une valeur qui ne respecte pas le registre', function (string $key, mixed $value) {
    settings()->set($key, $value);
})->throws(ValidationException::class)->with([
    ['files.disk_alert_percent', 150],
    ['files.disk_alert_percent', 'beaucoup'],
    ['rate_limit.login', ['max_attempts' => 0, 'decay_minutes' => 15]],
    ['rate_limit.login', ['max_attempts' => 5]],
    ['messaging.send_window', ['from' => '20:00', 'to' => '07:00']],
    ['numbering.membership.pattern', '{YYYY}-{ABR}'],
]);

it('refuse d\'écrire un paramètre à un niveau qu\'il n\'admet pas, ou un paramètre interne', function (string $key, bool $forCga) {
    $organizationId = $forCga ? Organization::factory()->create()->id : null;

    expect(fn () => settings()->set($key, 1, $organizationId))
        ->toThrow(fn (BusinessRuleException $e) => expect($e->errorCode)->toBe('setting_not_editable'));
})->with([
    'paramètre plateforme pour un CGA' => ['files.disk_alert_percent', true],
    'compteur interne' => ['settings.version', false],
]);

it('revient à la valeur héritée en supprimant la valeur enregistrée', function () {
    declareMixedSetting();
    $cga = Organization::factory()->create();
    settings()->set('essai.mixte', 45);
    settings()->set('essai.mixte', 60, $cga->id);

    settings()->reset('essai.mixte', $cga->id);
    expect(settings()->get('essai.mixte', $cga->id))->toBe(45);

    settings()->reset('essai.mixte');
    expect(settings()->get('essai.mixte', $cga->id))->toBe(30);
});

it('change de version à chaque modification', function () {
    expect(settings()->version())->toBe(0);

    settings()->set('files.disk_alert_percent', 90);
    settings()->set('files.disk_alert_percent', 85);
    settings()->reset('files.disk_alert_percent');

    expect(settings()->version())->toBe(3);
});

it('journalise chaque modification avec l\'auteur, l\'avant et l\'après', function () {
    $actor = User::factory()->create();

    settings()->set('files.disk_alert_percent', 90, actorId: $actor->id);
    settings()->set('files.disk_alert_percent', 85, actorId: $actor->id);

    $log = AuditLog::query()->where('subject_type', Setting::class)->where('event', 'updated')->sole();
    expect($log->changes['value'])->toBe(['90', '85'])
        ->and($log->changes['updated_by'] ?? null)->toBeNull();
});

it('fige les valeurs utilisées par un calcul conservé, avec la version', function () {
    settings()->set('files.disk_alert_percent', 90);

    expect(settings()->snapshot(['files.disk_alert_percent', 'pagination.max']))
        ->toBe(['settings.version' => 1, 'files.disk_alert_percent' => 90, 'pagination.max' => 100]);
});
