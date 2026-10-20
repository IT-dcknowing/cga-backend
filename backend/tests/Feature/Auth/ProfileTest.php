<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Models\OtpCode;
use App\Domain\Auth\Notifications\OneTimeCodeNotification;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\Spa;

/*
 * F-AUTH-05 Mon profil : nom, e-mail, mot de passe, préférences de notification (RG-NOT-04) ; le
 * changement de téléphone exige un code SMS envoyé au nouveau numéro.
 */

beforeEach(function () {
    Notification::fake();
    $this->user = Membership::factory()->create([
        'user_id' => User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Koné', 'phone' => '+2250700000001', 'email' => 'awa@cga.ci', 'email_verified_at' => now()]),
    ])->user;
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/login', ['identifier' => '0700000001', 'password' => 'password'])->assertOk();
});

/**
 * @param  array<string, mixed>  $data
 */
function profileCall(string $method, string $uri, array $data = []): TestResponse
{
    return test()->withHeaders(Spa::headers())->json($method, '/api/v1/me'.$uri, $data);
}

function phoneCode(string $phone): string
{
    $code = null;
    Notification::assertSentOnDemand(OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($phone, &$code): bool {
        $code = $notification->code;

        return $notifiable->routes['sms'] === $phone && $notification->purpose->value === 'phone_change';
    });

    return (string) $code;
}

it('exige une session sur chaque route du profil', function (string $method, string $uri) {
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/logout')->assertNoContent();

    profileCall($method, $uri)->assertUnauthorized();
})->with([
    ['GET', ''], ['PATCH', ''], ['POST', '/space'], ['POST', '/password'],
    ['POST', '/phone/code'], ['POST', '/phone'], ['PATCH', '/preferences'],
]);

describe('identité', function () {
    it('F-AUTH-05 modifie le nom et l\'e-mail ; un nouvel e-mail n\'est plus vérifié', function () {
        profileCall('PATCH', '', ['first_name' => 'Awa Marie', 'last_name' => 'Koné', 'email' => 'awa.kone@cga.ci'])
            ->assertOk()
            ->assertJsonPath('data.user.first_name', 'Awa Marie')
            ->assertJsonPath('data.user.email', 'awa.kone@cga.ci');

        expect($this->user->fresh()->email_verified_at)->toBeNull()
            ->and(AuditLog::query()->where('subject_id', $this->user->id)->where('event', 'updated')->latest('id')->first()->changes)
            ->toHaveKeys(['first_name', 'email']);
    });

    it('garde l\'e-mail vérifié quand il ne change pas', function () {
        profileCall('PATCH', '', ['first_name' => 'Awa', 'last_name' => 'Traoré', 'email' => 'awa@cga.ci'])->assertOk();

        expect($this->user->fresh()->email_verified_at)->not->toBeNull();
    });

    it('permet de retirer l\'e-mail', function () {
        profileCall('PATCH', '', ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => null])->assertOk()->assertJsonPath('data.user.email', null);
    });

    it('refuse un e-mail déjà pris par un autre compte, quelle que soit la casse', function () {
        User::factory()->create(['email' => 'pris@cga.ci']);

        profileCall('PATCH', '', ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => 'PRIS@cga.ci'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    });

    it('valide le profil', function () {
        profileCall('PATCH', '', ['first_name' => '', 'last_name' => str_repeat('a', 101), 'email' => 'pas-un-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email']);
    });
});

describe('mot de passe', function () {
    it('F-AUTH-05 change le mot de passe après contrôle de l\'actuel', function () {
        profileCall('POST', '/password', ['current_password' => 'password', 'password' => 'Abidjan-Plateau-2026'])->assertNoContent();

        expect(Hash::check('Abidjan-Plateau-2026', (string) $this->user->fresh()->password))->toBeTrue();
    });

    it('refuse un mot de passe actuel faux, identique, trop court ou courant', function (array $input, string $field) {
        profileCall('POST', '/password', $input)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(Hash::check('password', (string) $this->user->fresh()->password))->toBeTrue();
    })->with([
        'actuel faux' => [['current_password' => 'mauvais', 'password' => 'Abidjan-Plateau-2026'], 'current_password'],
        'identique' => [['current_password' => 'password', 'password' => 'password'], 'password'],
        'trop court' => [['current_password' => 'password', 'password' => 'Ab-12'], 'password'],
        'courant' => [['current_password' => 'password', 'password' => 'football'], 'password'],
    ]);
});

describe('téléphone', function () {
    it('F-AUTH-05 envoie un code au nouveau numéro, normalisé au format E.164', function () {
        profileCall('POST', '/phone/code', ['phone' => '05 01 02 03 04'])
            ->assertStatus(202)
            ->assertExactJson(['message' => 'Un code a été envoyé au nouveau numéro.']);

        expect(phoneCode('+2250501020304'))->toMatch('/^\d{6}$/')
            ->and(OtpCode::query()->sole())->target->toBe('+2250501020304')->purpose->value->toBe('phone_change');
    });

    it('F-AUTH-05 change le numéro avec le bon code', function () {
        profileCall('POST', '/phone/code', ['phone' => '0501020304']);

        profileCall('POST', '/phone', ['phone' => '+225 05 01 02 03 04', 'code' => phoneCode('+2250501020304')])
            ->assertOk()
            ->assertJsonPath('data.user.phone', '+2250501020304');

        expect($this->user->fresh()->phone_verified_at)->not->toBeNull();
        $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/logout');
        $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/login', ['identifier' => '0501020304', 'password' => 'password'])->assertOk();
    });

    it('refuse un code faux, en comptant les essais', function () {
        profileCall('POST', '/phone/code', ['phone' => '0501020304']);
        $wrong = phoneCode('+2250501020304') === '000000' ? '111111' : '000000';

        profileCall('POST', '/phone', ['phone' => '0501020304', 'code' => $wrong])
            ->assertStatus(409)->assertJsonPath('code', 'wrong_code')->assertJsonPath('details.attempts_left', 2);
        expect($this->user->fresh()->phone)->toBe('+2250700000001');
    });

    it('refuse un code envoyé à un autre numéro', function () {
        profileCall('POST', '/phone/code', ['phone' => '0501020304']);

        profileCall('POST', '/phone', ['phone' => '0501020399', 'code' => phoneCode('+2250501020304')])
            ->assertStatus(409)->assertJsonPath('code', 'code_expired');
    });

    it('RG-ACC-01 refuse un numéro qui identifie déjà un autre compte', function () {
        User::factory()->create(['phone' => '+2250501020304']);

        profileCall('POST', '/phone/code', ['phone' => '0501020304'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Ce numéro est déjà associé à un autre compte.');
        Notification::assertNothingSent();
    });

    it('RG-ACC-01 refuse le numéro pris par un autre compte entre le code et la confirmation', function () {
        profileCall('POST', '/phone/code', ['phone' => '0501020304']);
        $code = phoneCode('+2250501020304');
        User::factory()->create(['phone' => '+2250501020304']);

        // La validation de la confirmation ne contrôle que le format : l'Action refait le contrôle.
        profileCall('POST', '/phone', ['phone' => '0501020304', 'code' => $code])
            ->assertStatus(409)->assertJsonPath('code', 'phone_taken');
        expect($this->user->fresh()->phone)->toBe('+2250700000001');
    });

    it('limite à 3 demandes de code par heure et par numéro', function () {
        foreach (range(1, 3) as $request) {
            profileCall('POST', '/phone/code', ['phone' => '0501020304'])->assertStatus(202);
        }

        profileCall('POST', '/phone/code', ['phone' => '0501020304'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    });

    it('valide le numéro et le code', function () {
        profileCall('POST', '/phone/code', ['phone' => '12'])->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Le numéro de téléphone n\'est pas valide.');
        profileCall('POST', '/phone', ['phone' => 'abc', 'code' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'code']);
    });
});

describe('préférences (RG-NOT-04)', function () {
    it('F-AUTH-05 enregistre les choix SMS / WhatsApp et les renvoie dans la session', function () {
        profileCall('PATCH', '/preferences', ['sms' => true, 'whatsapp' => true])
            ->assertOk()->assertJsonPath('data.user.notification_preferences', ['sms' => true, 'whatsapp' => true]);
        profileCall('PATCH', '/preferences', ['sms' => false, 'whatsapp' => true])
            ->assertOk()->assertJsonPath('data.user.notification_preferences', ['sms' => false, 'whatsapp' => true]);

        expect(AuditLog::query()->where('event', 'notification_preferences_updated')->orderBy('id')->pluck('changes')->all())
            ->toEqual([['sms' => [null, true], 'whatsapp' => [null, true]], ['sms' => [true, false]]]);
    });

    it('n\'inscrit rien au journal des modifications quand rien ne change', function () {
        profileCall('PATCH', '/preferences', ['sms' => true, 'whatsapp' => false]);
        profileCall('PATCH', '/preferences', ['sms' => true, 'whatsapp' => false])->assertOk();

        expect(AuditLog::query()->where('event', 'notification_preferences_updated')->count())->toBe(1);
    });

    it('valide les préférences', function () {
        profileCall('PATCH', '/preferences', ['sms' => 'oui'])->assertUnprocessable()->assertJsonValidationErrors(['sms', 'whatsapp']);
    });
});
