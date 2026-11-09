<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notifications\Channels\WhatsappChannel;
use App\Domain\Notifications\Mail\PlatformMailTransport;
use App\Domain\Notifications\Models\MessageLog;
use App\Domain\Notifications\Models\MessagingProvider;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Notifications\Providers\LogWhatsappProvider;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Écran « Envois » et pilotes d'envoi (06-IMPLEMENTATION-PLAN étape 6.2, RG-NOT-05, F-ADM-06) :
 * identifiants saisis à l'écran, chiffrés et jamais réaffichés ; un compte actif par canal ; message de
 * test ; envois SMS (Orange CI, agrégateur HTTP), WhatsApp (Cloud API) et e-mail (SMTP) lus en base.
 * Aucun appel réseau réel : Http::fake.
 */

final class ProvidersTestNotification extends PlatformNotification
{
    public function type(): string
    {
        return 'providers_test';
    }

    public function replacements(): array
    {
        return ['name' => 'Awa'];
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['queue.default' => 'sync', 'notifications.types.providers_test' => ['channels' => ['sms', 'whatsapp'], 'essential' => true, 'whatsapp_template' => 'echeance_rappel']]);
    $this->admin = Membership::factory()->role(Role::PlatformAdmin)->create();
});

function orangePayload(array $overrides = []): array
{
    return [
        'channel' => 'sms', 'driver' => 'orange_ci', 'label' => 'Orange CI production',
        'settings' => ['base_url' => 'https://api.orange.test', 'sender_address' => '+2250000', 'sender_name' => 'CGACONNECT', 'cost_units' => 25],
        'secrets' => ['client_id' => 'client-abcd1234', 'client_secret' => 'secret-wxyz9876'],
        ...$overrides,
    ];
}

function createProvider(Membership $admin, array $payload): string
{
    return inSpace($admin)->postJson('/api/v1/platform/messaging', $payload)->assertCreated()->json('data.id');
}

it('RG-NOT-05 · enregistre un compte : identifiants chiffrés, jamais réaffichés, absents du journal', function () {
    $id = inSpace($this->admin)->postJson('/api/v1/platform/messaging', orangePayload())
        ->assertCreated()
        ->assertJsonPath('data.secrets.client_secret', '••••9876')
        ->assertJsonPath('data.settings.sender_name', 'CGACONNECT')
        ->assertJsonPath('data.is_active', false)
        ->json('data.id');

    expect(DB::table('messaging_providers')->where('id', $id)->value('secrets'))->not->toContain('secret-wxyz9876')
        ->and(MessagingProvider::query()->findOrFail($id)->secrets['client_secret'])->toBe('secret-wxyz9876')
        ->and(json_encode(AuditLog::query()->where('subject_id', $id)->get()->toArray()))->not->toContain('secret-wxyz9876');

    // Un identifiant non saisi garde sa valeur.
    inSpace($this->admin)->putJson("/api/v1/platform/messaging/{$id}", orangePayload(['label' => 'Orange CI', 'secrets' => ['client_id' => '', 'client_secret' => 'nouveau-secret-0001']]))
        ->assertOk()->assertJsonPath('data.secrets.client_id', '••••1234')->assertJsonPath('data.secrets.client_secret', '••••0001');
});

it('refuse un pilote d\'un autre canal, un identifiant manquant, un champ inconnu', function () {
    inSpace($this->admin)->postJson('/api/v1/platform/messaging', orangePayload(['channel' => 'email']))->assertUnprocessable()->assertJsonValidationErrors('driver');
    inSpace($this->admin)->postJson('/api/v1/platform/messaging', orangePayload(['secrets' => ['client_id' => 'x']]))->assertUnprocessable()->assertJsonValidationErrors('secrets.client_secret');
    inSpace($this->admin)->postJson('/api/v1/platform/messaging', orangePayload(['settings' => ['base_url' => 'http://non-chiffre.test', 'sender_address' => '+2250000', 'inconnu' => 1]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['settings', 'settings.base_url']);
});

it('RG-NOT-05 · un seul compte actif par canal ; Orange CI envoie avec son jeton et son expéditeur', function () {
    $first = createProvider($this->admin, orangePayload());
    $second = createProvider($this->admin, orangePayload(['label' => 'Orange CI secours']));
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$first}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$second}/activate")->assertOk();
    expect(MessagingProvider::query()->where('is_active', true)->pluck('id')->all())->toBe([$second]);

    Http::fake([
        'api.orange.test/oauth/v3/token' => Http::response(['access_token' => 'jeton-orange']),
        'api.orange.test/smsmessaging/*' => Http::response(['outboundSMSMessageRequest' => ['resourceURL' => 'https://api.orange.test/smsmessaging/v1/outbound/tel/requests/msg-42']], 201),
    ]);
    $agent = Membership::factory()->role(Role::CgaManager)->create();
    $agent->user->notifyNow(new ProvidersTestNotification($agent->organization_id, $agent->id));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'smsmessaging') && $request->hasHeader('Authorization', 'Bearer jeton-orange')
        && $request['outboundSMSMessageRequest']['address'] === 'tel:'.$agent->user->phone && $request['outboundSMSMessageRequest']['senderName'] === 'CGACONNECT');
    expect(MessageLog::query()->where('channel', 'sms')->sole())->status->toBe('sent')->provider_message_id->toBe('msg-42');

    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$second}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
    expect((new ProvidersTestNotification($agent->organization_id, $agent->id))->via($agent->user))->toBe(['database', 'mail']);
});

it('message de test : réponse du fournisseur renvoyée et gardée ; un échec est rapporté sans erreur', function () {
    $id = createProvider($this->admin, [
        'channel' => 'sms', 'driver' => 'http_generic', 'label' => 'Agrégateur',
        'settings' => ['url' => 'https://sms.agregateur.test/send', 'key_header' => 'X-Api-Key', 'sender' => 'CGACONNECT', 'to_field' => 'to', 'text_field' => 'message', 'sender_field' => 'from', 'id_path' => 'data.id'],
        'secrets' => ['api_key' => 'cle-aggregateur-5555'],
    ]);
    Http::fakeSequence('sms.agregateur.test/*')->push(['data' => ['id' => 'agg-1']])->push('quota insuffisant', 402);

    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/test", ['to' => '0700000001'])
        ->assertOk()->assertJsonPath('data', ['ok' => true, 'provider_message_id' => 'agg-1', 'provider_response' => null]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Api-Key', 'cle-aggregateur-5555') && $request['to'] === '+2250700000001' && $request['from'] === 'CGACONNECT');

    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/test", ['to' => '0700000001'])
        ->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.provider_response', 'quota insuffisant');
    expect(MessagingProvider::query()->findOrFail($id))->last_test_result->toBe('quota insuffisant')->last_test_at->not->toBeNull();
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/test", ['to' => 'pas-un-numero'])->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('WhatsApp Cloud API : modèle pré-approuvé du compte, numéro sans « + »', function () {
    $id = createProvider($this->admin, [
        'channel' => 'whatsapp', 'driver' => 'whatsapp_cloud', 'label' => 'WhatsApp Business',
        'settings' => ['base_url' => 'https://graph.whatsapp.test/v19.0', 'phone_number_id' => '123456', 'templates' => ['echeance_rappel' => ['name' => 'rappel_echeance', 'language' => 'fr']]],
        'secrets' => ['access_token' => 'jeton-meta-7777'],
    ]);
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/activate")->assertOk();
    Http::fakeSequence('graph.whatsapp.test/*')->push(['messages' => [['id' => 'wamid.XYZ']]])->push('jeton expiré', 401);
    $agent = Membership::factory()->role(Role::CgaManager)->create();
    config(['notifications.types.providers_test.channels' => ['whatsapp']]);

    $agent->user->notifyNow(new ProvidersTestNotification($agent->organization_id, $agent->id));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.whatsapp.test/v19.0/123456/messages'
        && $request['template']['name'] === 'rappel_echeance' && $request['to'] === ltrim($agent->user->phone, '+')
        && $request['template']['components'][0]['parameters'][0]['text'] === 'Awa');
    expect(MessageLog::query()->where('channel', 'whatsapp')->sole()->provider_message_id)->toBe('wamid.XYZ');

    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/test", ['to' => '0700000001'])->assertOk()->assertJsonPath('data.ok', false);
});

it('e-mail : le transport « platform » passe par le SMTP du compte actif, sinon par le mailer de repli', function () {
    $id = createProvider($this->admin, [
        'channel' => 'email', 'driver' => 'smtp', 'label' => 'SMTP transactionnel',
        'settings' => ['host' => 'smtp.fournisseur.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'no-reply@cga-connect.test', 'from_name' => 'CGA Connect'],
        'secrets' => ['username' => 'apikey', 'password' => 'mot-de-passe-smtp'],
    ]);
    $transport = PlatformMailTransport::smtp(MessagingProvider::query()->findOrFail($id));
    expect($transport->getStream()->getHost())->toBe('smtp.fournisseur.test')
        ->and($transport->getStream()->getPort())->toBe(587)
        ->and($transport->getUsername())->toBe('apikey');

    config(['mail.mailers.platform.fallback' => 'array']);
    Mail::mailer('platform')->raw('Bonjour', static fn ($message) => $message->to('awa@cga.test')->subject('Essai'));
    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);

    $log = createProvider($this->admin, ['channel' => 'email', 'driver' => 'log', 'label' => 'Journal', 'settings' => [], 'secrets' => []]);
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$log}/test", ['to' => 'awa@cga.test'])->assertOk()->assertJsonPath('data.ok', true);
});

it('réservé à l\'administrateur de la plateforme', function (Role $role) {
    $id = createProvider($this->admin, orangePayload());

    inSpace(Membership::factory()->role($role)->create())->getJson('/api/v1/platform/messaging')->assertForbidden();
    inSpace(Membership::factory()->role($role)->create())->postJson("/api/v1/platform/messaging/{$id}/activate")->assertForbidden();
    inSpace($this->admin)->getJson('/api/v1/platform/messaging')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.drivers.orange_ci.secrets', ['client_id', 'client_secret']);
})->with([
    'gérant de CGA' => [Role::CgaManager],
    'présidente du CNCGA' => [Role::CncgaPresident],
]);

it('SMTP injoignable : le test et l\'envoi par le transport « platform » échouent sans rien perdre ; WhatsApp en journal', function () {
    $id = createProvider($this->admin, [
        'channel' => 'email', 'driver' => 'smtp', 'label' => 'SMTP local fermé',
        'settings' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => null, 'from_address' => 'no-reply@cga-connect.test', 'from_name' => 'CGA Connect'],
        'secrets' => [],
    ]);
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/activate")->assertOk();

    $result = inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/test", ['to' => 'awa@cga.test'])->assertOk()->assertJsonPath('data.ok', false);
    expect($result->json('data.provider_response'))->not->toBeEmpty()
        ->and(MessagingProvider::query()->findOrFail($id)->last_test_result)->not->toBe('ok')
        ->and((string) app('mail.manager')->mailer('platform')->getSymfonyTransport())->toBe('platform');
    expect(fn () => Mail::mailer('platform')->raw('Bonjour', static fn ($message) => $message->to('awa@cga.test')->subject('Essai')))
        ->toThrow(TransportException::class);

    $whatsapp = createProvider($this->admin, ['channel' => 'whatsapp', 'driver' => 'log', 'label' => 'WhatsApp journal', 'settings' => [], 'secrets' => []]);
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$whatsapp}/test", ['to' => '0700000001'])
        ->assertOk()->assertJsonPath('data.ok', true);
    expect(app(LogWhatsappProvider::class)->send('+2250700000001', 'rappel', 'fr', ['Awa']))->toStartWith('log-');
});

it('canal WhatsApp : notification sans WhatsApp ou destinataire sans numéro refusés ; panne du fournisseur tracée puis relancée', function () {
    $agent = Membership::factory()->role(Role::CgaManager)->create();
    $channel = app(WhatsappChannel::class);
    $plain = new class extends Notification {};

    expect(fn () => $channel->send($agent->user, $plain))->toThrow(LogicException::class)
        ->and(fn () => $channel->send(new AnonymousNotifiable, new ProvidersTestNotification($agent->organization_id, $agent->id)))->toThrow(LogicException::class);

    $id = createProvider($this->admin, [
        'channel' => 'whatsapp', 'driver' => 'whatsapp_cloud', 'label' => 'WhatsApp Business',
        'settings' => ['base_url' => 'https://graph.whatsapp.test/v19.0', 'phone_number_id' => '123456', 'templates' => []],
        'secrets' => ['access_token' => 'jeton-meta-7777'],
    ]);
    inSpace($this->admin)->postJson("/api/v1/platform/messaging/{$id}/activate")->assertOk();
    Http::fake(['graph.whatsapp.test/*' => Http::response('jeton expiré', 401)]);

    expect(fn () => app(WhatsappChannel::class)->send($agent->user, new ProvidersTestNotification($agent->organization_id, $agent->id)))
        ->toThrow(ExternalServiceException::class);
    expect(MessageLog::query()->where('channel', 'whatsapp')->sole())->status->toBe('failed');
});
