<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Auth\Notifications\OneTimeCodeNotification;
use App\Domain\Notifications\Channels\SmsChannel;
use App\Domain\Notifications\Contracts\SmsProvider;
use App\Domain\Notifications\Listeners\LogNotificationActivity;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Str;

/*
 * Canal « sms » et pilote « log » (02-TRD §10) ; chaque envoi au journal d'activité, sans le texte
 * du message (02-TRD §6.5).
 */

function smsOutbox(): string
{
    $outbox = storage_path('framework/testing/sms-'.Str::random(8).'.log');
    config(['logging.channels.sms.path' => $outbox]);

    return $outbox;
}

it('écrit le SMS du code dans la boîte d\'envoi du pilote « log » et journalise l\'envoi sans le code', function () {
    config(['queue.default' => 'sync']);
    $outbox = smsOutbox();
    Membership::factory()->create(['user_id' => User::factory()->create(['phone' => '+2250700000001'])]);

    $this->postJson('/api/v1/auth/password/code', ['identifier' => '0700000001'])->assertStatus(202);

    $sms = (string) file_get_contents($outbox);
    File::delete($outbox);
    preg_match('/code de réinitialisation est (\d{6})/', $sms, $match);
    $log = ActivityLog::query()->where('action', 'notification.sms')->sole();
    expect($sms)->toContain('SMS à +2250700000001')
        ->and($match)->toHaveCount(2)
        ->and($log->result->value)->toBe('success')
        ->and($log->actor_label)->toBe('system:notifications')
        ->and($log->params)->toBe(['to' => '+2250700000001', 'notification' => 'OneTimeCodeNotification'])
        ->and(json_encode($log->params))->not->toContain($match[1]);
});

it('ne garde jamais le code en clair dans la table des files : la tâche est chiffrée', function () {
    Membership::factory()->create(['user_id' => User::factory()->create(['phone' => '+2250700000001'])]);

    $this->postJson('/api/v1/auth/password/code', ['identifier' => '0700000001'])->assertStatus(202);

    $payload = json_decode((string) DB::table('jobs')->where('queue', OneTimeCodeNotification::QUEUE)->value('payload'), true);
    $command = $payload['data']['command'];
    $plain = (string) decrypt($command);
    preg_match('/"code";s:6:"(\d{6})"/', $plain, $code);

    expect($command)->not->toStartWith('O:')->not->toContain('+2250700000001')
        ->and($plain)->toContain('OneTimeCodeNotification')
        ->and($code)->toHaveCount(2)
        ->and($command)->not->toContain($code[1]);
});

it('envoie au numéro du compte pour une notification adressée à un utilisateur', function () {
    $outbox = smsOutbox();
    $user = User::factory()->create(['phone' => '+2250700000009']);

    $user->notifyNow(new OneTimeCodeNotification(OtpPurpose::PhoneChange, '123456', 10, 'sms'));

    expect((string) file_get_contents($outbox))->toContain('SMS à +2250700000009 : CGA Connect : votre code de confirmation du nouveau numéro est 123456');
    File::delete($outbox);
    expect(ActivityLog::query()->where('action', 'notification.sms')->sole())
        ->subject_type->toBe(User::class)
        ->subject_id->toBe($user->id);
});

it('refuse une notification sans texte SMS', function () {
    $notification = new class extends Notification {};

    expect(fn () => app(SmsChannel::class)->send(new AnonymousNotifiable, $notification))
        ->toThrow(LogicException::class, 'ne prévoit pas d\'envoi par SMS');
});

it('refuse un envoi sans numéro', function () {
    $notification = new OneTimeCodeNotification(OtpPurpose::PasswordReset, '123456', 10, 'sms');

    expect(fn () => app(SmsChannel::class)->send(new AnonymousNotifiable, $notification))
        ->toThrow(LogicException::class, 'Aucun numéro');
});

it('passe par le fournisseur configuré', function () {
    $provider = Mockery::mock(SmsProvider::class);
    $provider->shouldReceive('send')->once()->with('+2250700000001', Mockery::pattern('/123456/'));
    app()->instance(SmsProvider::class, $provider);

    Notifications::route('sms', '+2250700000001')->notifyNow(new OneTimeCodeNotification(OtpPurpose::PasswordReset, '123456', 10, 'sms'));
});

it('journalise un envoi en échec', function () {
    app(LogNotificationActivity::class)->failed(new NotificationFailed(
        (new AnonymousNotifiable)->route('mail', 'awa@cga.ci'),
        new OneTimeCodeNotification(OtpPurpose::PasswordReset, '123456', 10, 'mail'),
        'mail',
    ));

    expect(ActivityLog::query()->where('action', 'notification.mail')->sole())
        ->result->value->toBe('error')
        ->params->toBe(['to' => 'awa@cga.ci', 'notification' => 'OneTimeCodeNotification']);
});
