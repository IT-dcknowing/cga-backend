<?php

use App\Domain\Notifications\EventNotification;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Actions\ProcessUserInactivity;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Organization::factory()->create(['type' => OrganizationType::Platform]);
});

it('RG-ACC-06 désactive à 12 mois, informe à 23 mois et anonymise à 24 mois en préservant les liens', function () {
    $today = CarbonImmutable::parse('2026-11-05 12:00:00');
    $disable = User::factory()->create(['last_login_at' => $today->subMonths(12)]);
    $notice = User::factory()->create(['last_login_at' => $today->subMonths(23), 'status' => UserStatus::Disabled]);
    $expired = User::factory()->create([
        'last_login_at' => $today->subMonths(24), 'status' => UserStatus::Disabled,
        'inactivity_notice_sent_at' => $today->subMonth(),
    ]);
    $fresh = User::factory()->create(['last_login_at' => $today->subMonths(11)]);

    $result = app(ProcessUserInactivity::class)->execute($today);

    expect($result)->toBe(['disabled' => 1, 'notified' => 1, 'anonymized' => 1]);
    expect($disable->fresh()->status)->toBe(UserStatus::Disabled);
    expect($notice->fresh()->inactivity_notice_sent_at)->not->toBeNull();
    Notification::assertSentTo($notice, EventNotification::class, fn (EventNotification $n): bool => $n->type() === 'account_inactivity_notice' && $n->replacements()['delete_date'] === '2026-12-05');
    expect($expired->fresh()->status)->toBe(UserStatus::Deleted)
        ->and($expired->fresh()->first_name)->toBe('Ancien')
        ->and($expired->fresh()->last_name)->toBe('utilisateur')
        ->and($expired->fresh()->email)->toBeNull()
        ->and($expired->fresh()->password)->toBeNull()
        ->and($fresh->fresh()->status)->toBe(UserStatus::Active);
});

it('RG-ACC-06 traite les comptes sans connexion depuis leur création et ne réinforme pas', function () {
    $today = CarbonImmutable::parse('2026-11-05');
    $user = User::factory()->create([
        'last_login_at' => null, 'created_at' => $today->subMonths(23), 'updated_at' => $today->subMonths(23),
        'status' => UserStatus::Disabled, 'inactivity_notice_sent_at' => $today->subDay(),
    ]);

    $result = app(ProcessUserInactivity::class)->execute($today);

    expect($result['notified'])->toBe(0);
    Notification::assertNothingSent();
    expect($user->fresh()->status)->toBe(UserStatus::Disabled);
});

it('users:inactivity est exécutable par la commande', function () {
    Artisan::call('users:inactivity');

    expect(Artisan::output())->toContain('0 désactivé(s)');
});
