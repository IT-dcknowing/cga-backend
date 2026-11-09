<?php

use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Notifications\OneTimeCodeNotification;
use App\Domain\Notifications\Actions\SetNotificationPreferences;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Contracts\WhatsappProvider;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Notifications\Support\MessagingProviders;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\ExternalServiceException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Moteur de notifications (06-IMPLEMENTATION-PLAN étape 6.1, PRD M13, 02-TRD §10) : canaux par type,
 * plateforme et e-mail sans limite (RG-NOT-01), plage d'envoi des SMS et WhatsApp (RG-NOT-02), SMS à la
 * place de l'e-mail absent (RG-NOT-03), consentement (RG-NOT-04), repli sans compte d'envoi (RG-NOT-05),
 * quota SMS du centre, journal des envois, cloche de l'espace actif.
 */

final class EngineTestNotification extends PlatformNotification
{
    public function __construct(string $organizationId, ?string $membershipId, private readonly string $kind)
    {
        parent::__construct($organizationId, $membershipId);
    }

    public function type(): string
    {
        return $this->kind;
    }

    public function replacements(): array
    {
        return ['name' => 'Awa'];
    }

    public function link(): ?string
    {
        return '/cga/echeances';
    }
}

beforeEach(function () {
    config(['queue.default' => 'sync', 'notifications.types' => [
        'engine_essential' => ['channels' => ['database', 'mail', 'sms', 'whatsapp'], 'essential' => true, 'whatsapp_template' => 'echeance_rappel'],
        'engine_optional' => ['channels' => ['database', 'mail', 'sms', 'whatsapp'], 'essential' => false],
        'engine_contractual' => ['channels' => ['database', 'mail'], 'essential' => true, 'contractual' => true],
    ]]);
    // Le fichier de traduction est chargé avant d'y ajouter les textes des types d'essai.
    trans('notifications.open');
    foreach (['engine_essential', 'engine_optional', 'engine_contractual'] as $type) {
        app('translator')->addLines([
            "notifications.types.{$type}.title" => 'Échéance proche',
            "notifications.types.{$type}.body" => 'Bonjour :name, une échéance arrive.',
            "notifications.types.{$type}.sms" => 'CGA : échéance proche pour :name.',
        ], 'fr');
    }
    $this->travelTo('2026-11-03 10:00:00');
    $this->agent = Membership::factory()->role(Role::CgaManager)->create();
    $this->user = $this->agent->user;
});

function activateProvider(string $channel, array $settings = []): void
{
    $provider = new MessagingProvider;
    $provider->channel = $channel;
    $provider->driver = 'log';
    $provider->label = "Compte {$channel} de test";
    $provider->settings = $settings;
    $provider->secrets = ['token' => 'secret-de-test'];
    $provider->is_active = true;
    $provider->save();
    app(MessagingProviders::class)->forget();
}

function engineNotification(Membership $membership, string $type = 'engine_essential'): EngineTestNotification
{
    return new EngineTestNotification($membership->organization_id, $membership->id, $type);
}

it('RG-NOT-01 · plateforme et e-mail ne sont jamais limités', function () {
    app(SettingsService::class)->set('sms.monthly_quota', 0, $this->agent->organization_id);

    foreach (range(1, 25) as $i) {
        $this->user->notifyNow(engineNotification($this->agent));
    }

    expect($this->user->notifications()->count())->toBe(25)
        ->and(MessageLog::query()->where('channel', 'email')->where('status', 'sent')->count())->toBe(25)
        ->and($this->user->notifications()->first()->data)->toMatchArray([
            'type' => 'engine_essential', 'organization_id' => $this->agent->organization_id, 'membership_id' => $this->agent->id,
            'title' => 'Échéance proche', 'body' => 'Bonjour Awa, une échéance arrive.', 'link' => '/cga/echeances',
        ]);
});

it('RG-NOT-02 · SMS et WhatsApp hors de 7 h – 20 h partent à l\'ouverture suivante', function () {
    $notification = engineNotification($this->agent);

    expect($notification->withDelay($this->user))->toBe([]);
    $this->travelTo('2026-11-03 21:30:00');
    expect(array_map(static fn (CarbonImmutable $at): string => $at->toDateTimeString(), $notification->withDelay($this->user)))
        ->toBe(['sms' => '2026-11-04 07:00:00', 'whatsapp' => '2026-11-04 07:00:00']);
    $this->travelTo('2026-11-03 06:15:00');
    expect($notification->withDelay($this->user)['sms']->toDateTimeString())->toBe('2026-11-03 07:00:00');
});

it('RG-NOT-03 · sans e-mail, un événement contractuel part par SMS', function () {
    activateProvider('sms');
    $notification = engineNotification($this->agent, 'engine_contractual');

    expect($notification->via($this->user))->toBe(['database', 'mail']);
    $this->user->forceFill(['email' => null])->save();
    expect($notification->via($this->user->fresh()))->toBe(['database', 'sms']);
});

it('RG-NOT-04 · SMS et WhatsApp non essentiels seulement avec le consentement ; les essentiels toujours', function () {
    activateProvider('sms');
    activateProvider('whatsapp');

    expect(engineNotification($this->agent, 'engine_optional')->via($this->user))->toBe(['database', 'mail']);
    app(SetNotificationPreferences::class)->execute($this->user, ['sms' => true, 'whatsapp' => false]);
    expect(engineNotification($this->agent, 'engine_optional')->via($this->user))->toBe(['database', 'mail', 'sms']);
    app(SetNotificationPreferences::class)->execute($this->user, ['sms' => false, 'whatsapp' => false]);
    expect(engineNotification($this->agent, 'engine_optional')->via($this->user))->toBe(['database', 'mail'])
        ->and(engineNotification($this->agent)->via($this->user))->toBe(['database', 'mail', 'sms', 'whatsapp']);
});

it('RG-NOT-05 · sans compte d\'envoi actif, SMS et WhatsApp sont remplacés par la plateforme et l\'e-mail', function () {
    expect(engineNotification($this->agent)->via($this->user))->toBe(['database', 'mail']);

    $this->user->forceFill(['email' => null])->save();
    expect(engineNotification($this->agent)->via($this->user->fresh()))->toBe(['database']);
    expect(fn () => (new EngineTestNotification($this->agent->organization_id, $this->agent->id, 'inconnu'))->via($this->user))
        ->toThrow(LogicException::class, 'non déclaré');
});

it('consomme le quota SMS mensuel du centre ; au-delà, « Quota épuisé » ; les codes de connexion sont hors quota', function () {
    activateProvider('sms');
    app(SettingsService::class)->set('sms.monthly_quota', 2, $this->agent->organization_id);
    config(['notifications.types.engine_essential.channels' => ['sms']]);

    foreach (range(1, 3) as $i) {
        $this->user->notifyNow(engineNotification($this->agent));
    }
    $this->user->notifyNow(new OneTimeCodeNotification(OtpPurpose::PasswordReset, '123456', 10, 'sms'));

    $logs = MessageLog::query()->where('channel', 'sms')->orderBy('id')->get();
    expect($logs->pluck('status')->all())->toBe(['sent', 'sent', 'failed', 'sent'])
        ->and($logs[0]->provider_message_id)->toStartWith('log-')
        ->and($logs[0])->organization_id->toBe($this->agent->organization_id)->notification_type->toBe('engine_essential')->recipient->toBe($this->user->phone)
        ->and($logs[2]->error)->toContain('Quota épuisé')
        ->and($logs[3])->organization_id->toBeNull()->notification_type->toBe('OneTimeCodeNotification')
        ->and(DB::table('sms_quotas')->where('organization_id', $this->agent->organization_id)->value('used'))->toBe(2);
});

it('WhatsApp : modèle pré-approuvé du compte d\'envoi ; un échec du fournisseur est journalisé puis relancé', function () {
    activateProvider('whatsapp', ['templates' => ['echeance_rappel' => ['name' => 'rappel_echeance_v2', 'language' => 'fr']]]);
    activateProvider('sms');
    config(['notifications.types.engine_essential.channels' => ['whatsapp']]);
    $whatsapp = Mockery::mock(WhatsappProvider::class);
    $whatsapp->shouldReceive('send')->once()->with($this->user->phone, 'rappel_echeance_v2', 'fr', ['Awa'])->andReturn('wamid.1');
    app()->instance(WhatsappProvider::class, $whatsapp);

    $this->user->notifyNow(engineNotification($this->agent));
    expect(MessageLog::query()->where('channel', 'whatsapp')->sole())->status->toBe('sent')->provider_message_id->toBe('wamid.1');

    config(['notifications.types.engine_essential.channels' => ['sms']]);
    $sms = Mockery::mock(SmsProvider::class);
    $sms->shouldReceive('send')->andThrow(new ExternalServiceException('sms'));
    app()->instance(SmsProvider::class, $sms);
    expect(fn () => $this->user->notifyNow(engineNotification($this->agent)))->toThrow(ExternalServiceException::class);
    expect(MessageLog::query()->where('channel', 'sms')->sole())->status->toBe('failed')->error->toContain('sms');
});

describe('cloche de l\'espace actif', function () {
    it('liste les notifications de l\'espace actif, les marque lues', function () {
        $other = Membership::factory()->role(Role::CgaAccountant)->create(['user_id' => $this->user->id]);
        $this->user->notifyNow(engineNotification($this->agent));
        $this->user->notifyNow(engineNotification($this->agent));
        $this->user->notifyNow(engineNotification($other));
        $foreign = Membership::factory()->role(Role::CgaManager)->create();
        $foreign->user->notifyNow(engineNotification($foreign));

        $list = inSpace($this->agent)->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2)->assertJsonPath('data.0.title', 'Échéance proche');
        $id = $list->json('data.0.id');

        inSpace($this->agent)->postJson("/api/v1/me/notifications/{$id}/read")->assertOk()->assertJsonPath('data.read_at', now()->toIso8601ZuluString());
        inSpace($this->agent)->getJson('/api/v1/me/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);
        inSpace($this->agent)->postJson('/api/v1/me/notifications/'.$foreign->user->notifications()->value('id').'/read')->assertNotFound();
        inSpace($this->agent)->postJson('/api/v1/me/notifications/'.$this->user->notifications()->where('data->membership_id', $other->id)->value('id').'/read')->assertNotFound();
        inSpace($this->agent)->postJson('/api/v1/me/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);
        inSpace($other)->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);
        inSpace($this->agent)->getJson('/api/v1/me/notifications?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    });

    it('exige une session', function () {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    });
});
