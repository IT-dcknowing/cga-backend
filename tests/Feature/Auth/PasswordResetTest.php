<?php

use App\Domain\Auth\Enums\OtpPurpose;
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
 * F-AUTH-03 Mot de passe oublié (02-TRD §6.1, 03-APPFLOW §2) : code à 6 chiffres par SMS (ou par
 * e-mail si l'identifiant saisi est l'e-mail), valable 10 minutes, 3 essais, 3 demandes par heure ;
 * la réponse ne révèle jamais si le compte existe.
 */

beforeEach(function () {
    Notification::fake();
});

function requestCode(string $identifier): TestResponse
{
    return test()->withHeaders(Spa::headers())->postJson('/api/v1/auth/password/code', ['identifier' => $identifier]);
}

function resetPassword(string $identifier, string $code, string $password = 'Abidjan-Plateau-2026'): TestResponse
{
    return test()->withHeaders(Spa::headers())->postJson('/api/v1/auth/password/reset', [
        'identifier' => $identifier,
        'code' => $code,
        'password' => $password,
    ]);
}

/**
 * Code envoyé à ce destinataire, lu dans la notification simulée.
 */
function sentCode(string $route, string $channel = 'sms'): string
{
    $code = null;
    Notification::assertSentOnDemand(OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($route, $channel, &$code): bool {
        if (($notifiable->routes[$channel] ?? null) !== $route) {
            return false;
        }
        $code = $notification->code;

        return true;
    });

    return (string) $code;
}

function resettableAccount(): User
{
    return Membership::factory()->create([
        'user_id' => User::factory()->create(['phone' => '+2250700000001', 'email' => 'awa@cga.ci']),
    ])->user;
}

it('F-AUTH-03 envoie un code à 6 chiffres par SMS au téléphone saisi', function () {
    $user = resettableAccount();

    requestCode('07 00 00 00 01')->assertStatus(202)->assertExactJson(['message' => 'Si ce compte existe, un code a été envoyé.']);

    $code = sentCode('+2250700000001');
    $otp = OtpCode::query()->where('user_id', $user->id)->sole();
    expect($code)->toMatch('/^\d{6}$/')
        ->and($otp->purpose->value)->toBe('password_reset')
        ->and($otp->target)->toBe('+2250700000001')
        ->and($otp->code_hash)->not->toContain($code)->toBe(OtpCode::fingerprint($code))
        ->and($otp->expires_at->diffInMinutes(now(), true))->toEqualWithDelta(10, 0.1);
});

it('F-AUTH-03 envoie le code par e-mail quand l\'identifiant saisi est l\'e-mail du compte', function () {
    resettableAccount();

    requestCode('AWA@cga.ci')->assertStatus(202);

    $code = sentCode('awa@cga.ci', 'mail');
    $mail = (new OneTimeCodeNotification(OtpPurpose::PasswordReset, $code, 10, 'mail'))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Votre code de réinitialisation CGA Connect')
        ->and($mail->introLines[0])->toContain($code);
});

it('A07 répond la même chose pour un compte inconnu ou inactif, sans rien envoyer', function () {
    $disabled = User::factory()->disabled()->create(['phone' => '+2250700000002']);

    $unknown = requestCode('0799999999')->assertStatus(202);
    $inactive = requestCode('0700000002')->assertStatus(202);

    expect($unknown->json())->toBe($inactive->json())->toBe(['message' => 'Si ce compte existe, un code a été envoyé.']);
    Notification::assertNothingSent();
    expect(OtpCode::query()->where('user_id', $disabled->id)->exists())->toBeFalse();
});

it('RG-ACC-04 limite à 3 demandes de code par heure et par identifiant, compte existant ou non', function (string $identifier) {
    resettableAccount();

    foreach (range(1, 3) as $request) {
        requestCode($identifier)->assertStatus(202);
    }
    requestCode($identifier)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

    $this->travel(61)->minutes();
    requestCode($identifier)->assertStatus(202);
})->with(['compte existant' => '0700000001', 'compte inconnu' => '0799999999']);

it('F-AUTH-03 remplace le mot de passe avec le bon code, une seule fois', function () {
    $user = resettableAccount();
    requestCode('0700000001');
    $code = sentCode('+2250700000001');

    resetPassword('0700000001', $code)->assertNoContent();

    expect(Hash::check('Abidjan-Plateau-2026', (string) $user->fresh()->password))->toBeTrue()
        ->and(OtpCode::query()->sole()->consumed_at)->not->toBeNull();
    resetPassword('0700000001', $code)->assertStatus(409)->assertJsonPath('code', 'code_expired');
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/login', ['identifier' => '0700000001', 'password' => 'Abidjan-Plateau-2026'])->assertOk();
});

it('F-AUTH-03 compte les essais : « Code incorrect (n essai(s) restant(s)) », puis plus aucun', function () {
    $user = resettableAccount();
    requestCode('0700000001');
    $code = sentCode('+2250700000001');
    $wrong = $code === '000000' ? '111111' : '000000';

    resetPassword('0700000001', $wrong)->assertStatus(409)
        ->assertJsonPath('code', 'wrong_code')
        ->assertJsonPath('details.attempts_left', 2)
        ->assertJsonPath('message', 'Code incorrect (2 essai(s) restant(s)).');
    resetPassword('0700000001', $wrong)->assertJsonPath('details.attempts_left', 1);
    resetPassword('0700000001', $wrong)->assertJsonPath('details.attempts_left', 0);

    resetPassword('0700000001', $code)->assertStatus(409)->assertJsonPath('code', 'code_expired');
    expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue()
        ->and(OtpCode::query()->sole()->attempts)->toBe(3);
});

it('F-AUTH-03 refuse un code de plus de 10 minutes', function () {
    resettableAccount();
    requestCode('0700000001');
    $code = sentCode('+2250700000001');

    $this->travel(11)->minutes();

    resetPassword('0700000001', $code)->assertStatus(409)->assertJsonPath('code', 'code_expired');
});

it('F-AUTH-03 n\'accepte que le dernier code envoyé', function () {
    resettableAccount();
    requestCode('0700000001');
    $first = sentCode('+2250700000001');
    Notification::fake();
    requestCode('0700000001');

    expect(OtpCode::query()->whereNull('consumed_at')->count())->toBe(1);
    if ($first !== sentCode('+2250700000001')) {
        resetPassword('0700000001', $first)->assertStatus(409);
    }
});

it('refuse un code demandé par téléphone et saisi avec l\'e-mail', function () {
    resettableAccount();
    requestCode('0700000001');

    resetPassword('awa@cga.ci', sentCode('+2250700000001'))->assertStatus(409)->assertJsonPath('code', 'code_expired');
});

it('A07 répond code_expired pour un compte inconnu', function () {
    resetPassword('0799999999', '123456')->assertStatus(409)->assertJsonPath('code', 'code_expired');
});

it('02-TRD §6.1 refuse un mot de passe trop court ou trop courant, sans consommer d\'essai', function (string $password, string $message) {
    resettableAccount();
    requestCode('0700000001');

    resetPassword('0700000001', sentCode('+2250700000001'), $password)
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', $message);
    expect(OtpCode::query()->sole()->attempts)->toBe(0);
})->with([
    'trop court' => ['Ab1-x', 'Le champ mot de passe doit contenir au moins 8 caractères.'],
    'courant' => ['password1', 'Ce mot de passe est trop courant. Choisissez-en un autre.'],
    'courant, autre casse' => ['QWERTY123', 'Ce mot de passe est trop courant. Choisissez-en un autre.'],
]);

it('valide la demande de code et la réinitialisation', function () {
    requestCode('')->assertUnprocessable()->assertJsonValidationErrors('identifier');
    resetPassword('0700000001', '12ab')->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('ne met jamais le code dans la réponse hors du poste de développement', function () {
    resettableAccount();
    config(['auth.echo_dev_messages' => true]);

    requestCode('0700000001')->assertJsonMissingPath('dev_message');
});

it('recopie le code dans la réponse en local, avec AUTH_ECHO_DEV_MESSAGES et le pilote « log »', function () {
    resettableAccount();
    config(['auth.echo_dev_messages' => true]);
    app()->instance('env', 'local');

    // Appel hors session (sans origine du SPA) : la vérification CSRF, active en local, ne s'applique pas.
    $response = $this->postJson('/api/v1/auth/password/code', ['identifier' => '0700000001'])->assertStatus(202);

    expect($response->json('dev_message'))->toBe(['channel' => 'sms', 'to' => '+2250700000001', 'code' => sentCode('+2250700000001')]);
});
