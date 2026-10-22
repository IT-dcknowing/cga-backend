<?php

use App\Domain\Auth\Data\IssuedCode;
use App\Domain\Auth\Support\DevMessages;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\Logging\AlertCounter;
use App\Support\Phones\PhoneNormalizer;
use App\Support\RateLimiting\AttemptLimiter;
use App\Support\Validation\Rules\AvailablePhone;
use App\Support\Validation\Rules\NotCommonPassword;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Validator;

/*
 * Briques de l'authentification (02-TRD §6.1) : limitation des essais, compteurs d'alertes,
 * téléphones, mots de passe courants, recopie des codes en développement.
 */

describe('AttemptLimiter', function () {
    it('applique le nombre d\'essais et la fenêtre du paramètre', function () {
        $limiter = app(AttemptLimiter::class);

        $limiter->consume('rate_limit.otp', 'cle');
        $limiter->consume('rate_limit.otp', 'cle');
        $limiter->consume('rate_limit.otp', 'cle');

        expect(fn () => $limiter->consume('rate_limit.otp', 'cle'))->toThrow(TooManyAttemptsException::class)
            ->and(fn () => $limiter->ensureAvailable('rate_limit.otp', 'autre-cle'))->not->toThrow(TooManyAttemptsException::class);

        $limiter->clear('rate_limit.otp', 'cle');
        $limiter->consume('rate_limit.otp', 'cle');
    });

    it('donne le délai d\'attente en minutes arrondies au-dessus et en secondes', function () {
        $exception = new TooManyAttemptsException(61);

        expect($exception->getMessage())->toBe('Trop de tentatives, réessayez dans 2 minutes.')
            ->and($exception->details)->toBe(['retry_after' => 61])
            ->and($exception->status)->toBe(429)
            ->and((new TooManyAttemptsException(0))->getMessage())->toContain('1 minutes');
    });
});

it('n\'échoue jamais à cause d\'un compteur d\'alertes indisponible : l\'incident est signalé', function () {
    $cache = Mockery::mock(Cache::class);
    $cache->shouldReceive('add')->andThrow(new RuntimeException('cache indisponible'));
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    (new AlertCounter($cache, config()))->increment('denied');

    expect($reported)->toBe(['cache indisponible']);
});

describe('téléphones', function () {
    it('normalise au format E.164 ou refuse', function () {
        $phones = app(PhoneNormalizer::class);

        expect($phones->e164('07 00 00 00 01'))->toBe('+2250700000001')
            ->and($phones->e164('+33 6 12 34 56 78'))->toBe('+33612345678')
            ->and($phones->e164('pas un numéro'))->toBeNull()
            ->and(fn () => $phones->e164OrFail('12'))->toThrow(InvalidArgumentException::class);
    });

    it('présente les numéros pour les documents : paires de chiffres pour le pays, format international sinon', function () {
        $phones = app(PhoneNormalizer::class);

        expect($phones->display('+2252722445501'))->toBe('+225 27 22 44 55 01')
            ->and($phones->display('+2250707000099'))->toBe('+225 07 07 00 00 99')
            ->and($phones->display('+33612345678'))->toBe('+33 6 12 34 56 78')
            ->and($phones->display('pas un numéro'))->toBe('pas un numéro');
    });

    it('accepte le numéro libre ou celui du compte lui-même', function () {
        $user = User::factory()->create(['phone' => '+2250700000001']);
        $rule = fn (?string $except) => ['phone' => [new AvailablePhone(app(PhoneNormalizer::class), $except)]];

        expect(Validator::make(['phone' => '0700000001'], $rule(null))->fails())->toBeTrue()
            ->and(Validator::make(['phone' => '0700000001'], $rule($user->id))->fails())->toBeFalse()
            ->and(Validator::make(['phone' => '0700000002'], $rule(null))->fails())->toBeFalse()
            ->and(Validator::make(['phone' => ['liste']], $rule(null))->fails())->toBeFalse();
    });
});

it('refuse de valider sans la liste des mots de passe courants', function () {
    $property = new ReflectionProperty(NotCommonPassword::class, 'list');
    $property->setValue(null, null);
    config(['auth.common_passwords_file' => storage_path('framework/testing/absente.txt')]);

    try {
        expect(fn () => Validator::make(['password' => 'Abidjan-2026'], ['password' => [new NotCommonPassword]])->fails())
            ->toThrow(RuntimeException::class, 'Liste des mots de passe courants illisible');
    } finally {
        $property->setValue(null, null);
    }
});

it('n\'écrit les préférences que par SetNotificationPreferences', function () {
    $preference = new NotificationPreference;

    expect(fn () => $preference->save())->toThrow(LogicException::class);
});

describe('recopie des codes en développement', function () {
    beforeEach(function () {
        app()->instance('env', 'local');
        config(['auth.echo_dev_messages' => true]);
    });

    it('recopie un code envoyé par e-mail avec le pilote « log » ou « array »', function (string $mailer, bool $echoed) {
        config(['mail.default' => $mailer]);

        $payload = app(DevMessages::class)->payload(new IssuedCode('mail', 'awa@cga.ci', '123456', 10));

        expect(isset($payload['dev_message']))->toBe($echoed);
    })->with([['log', true], ['array', true], ['smtp', false]]);

    it('ne recopie rien avec un vrai fournisseur SMS', function () {
        app()->instance(SmsProvider::class, Mockery::mock(SmsProvider::class));

        expect(app(DevMessages::class)->payload(new IssuedCode('sms', '+2250700000001', '123456', 10)))->toBe([]);
    });

    it('ne recopie rien sans le réglage AUTH_ECHO_DEV_MESSAGES, ni sans code envoyé', function () {
        expect(app(DevMessages::class)->payload(null))->toBe([]);

        config(['auth.echo_dev_messages' => false]);
        expect(app(DevMessages::class)->payload(new IssuedCode('sms', '+2250700000001', '123456', 10)))->toBe([]);
    });
});
