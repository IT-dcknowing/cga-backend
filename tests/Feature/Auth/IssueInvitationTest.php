<?php

use App\Domain\Auth\Actions\IssueInvitation;
use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Models\Invitation;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Log;
use Tests\Support\Spa;

/*
 * Émission d'un lien d'activation (RG-ACC-03, 02-TRD §6.1) et lien du premier administrateur de la
 * plateforme.
 */

it('RG-ACC-03 émet un jeton de 32 octets aléatoires, dont seule l\'empreinte est gardée, valable 7 jours', function () {
    $membership = Membership::factory()->invited()->create();

    $token = app(IssueInvitation::class)->execute($membership, InvitationChannel::SmsEmail);

    $invitation = Invitation::query()->sole();
    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->channel)->toBe(InvitationChannel::SmsEmail)
        ->and($invitation->expires_at->diffInDays($invitation->sent_at, true))->toEqualWithDelta(7, 0.01);
    $this->withHeaders(Spa::headers())->getJson('/api/v1/auth/invitations/'.$token)->assertOk()->assertJsonPath('data.status', 'valid');
});

it('RG-ACC-03 révoque le lien précédent quand l\'invitation est renvoyée', function () {
    $membership = Membership::factory()->invited()->create();
    $first = app(IssueInvitation::class)->execute($membership, InvitationChannel::Sms);

    $second = app(IssueInvitation::class)->execute($membership, InvitationChannel::Sms);

    expect($second)->not->toBe($first);
    $this->withHeaders(Spa::headers())->getJson('/api/v1/auth/invitations/'.$first)->assertJsonPath('data.status', 'invalid');
    $this->withHeaders(Spa::headers())->getJson('/api/v1/auth/invitations/'.$second)->assertJsonPath('data.status', 'valid');
});

it('suit la durée de validité réglée par l\'administrateur', function () {
    app(SettingsService::class)->set('invitation.validity_days', 3);

    app(IssueInvitation::class)->execute(Membership::factory()->invited()->create(), InvitationChannel::Sms);

    expect(Invitation::query()->sole()->expires_at->isSameDay(now()->addDays(3)))->toBeTrue();
});

it('n\'invite qu\'un rattachement en attente', function () {
    expect(fn () => app(IssueInvitation::class)->execute(Membership::factory()->create(), InvitationChannel::Sms))
        ->toThrow(LogicException::class);
});

describe('php artisan platform:invite-admin', function () {
    it('donne le lien d\'activation de l\'administrateur créé par PlatformSeeder', function () {
        config(['cga.seed.platform_admin.phone' => '+2250700000099', 'app.frontend_url' => 'https://cga-connect.ci']);
        $this->seed(PlatformSeeder::class);

        $this->artisan('platform:invite-admin')
            ->expectsOutputToContain('Administrateur Plateforme (+2250700000099)')
            ->expectsOutputToContain('https://cga-connect.ci/activer/')
            ->assertSuccessful();

        $membership = Membership::query()->where('role', Role::PlatformAdmin)->sole();
        expect(Invitation::query()->where('membership_id', $membership->id)->count())->toBe(1);
    });

    it('journalise l\'échec de l\'émission et le relance', function () {
        config(['cga.seed.platform_admin.phone' => '+2250700000099']);
        $this->seed(PlatformSeeder::class);
        $failing = Mockery::mock(IssueInvitation::class);
        $failing->shouldReceive('execute')->andThrow(new RuntimeException('base indisponible'));
        app()->instance(IssueInvitation::class, $failing);
        Log::spy();

        expect(fn () => $this->artisan('platform:invite-admin')->run())->toThrow(RuntimeException::class, 'base indisponible');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'administrateur de la plateforme'));
    });

    it('échoue sans administrateur en attente', function () {
        Membership::factory()->role(Role::PlatformAdmin)->create(['user_id' => User::factory()]);

        $this->artisan('platform:invite-admin')->assertFailed();
    });
});
