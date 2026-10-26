<?php

use App\Domain\Auth\Models\Invitation;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Spa;

/*
 * Jeu de démonstration (SEED_DEMO=true) : comptes fictifs du SPA et de la collection Postman.
 */

beforeEach(function () {
    config(['cga.seed.demo.enabled' => true]);
    Storage::fake();
});

it('crée les CGA et les comptes de démonstration, rejouable sans doublon', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Organization::query()->whereIn('numbering_abbreviation', ['DCK', 'DEMOB', 'DEMOS'])->count())->toBe(3)
        ->and(Organization::query()->where('numbering_abbreviation', 'DEMOS')->value('status'))->toBe(OrganizationStatus::Suspended)
        ->and(Organization::query()->where('agreement_number', 'like', '%/SEPMBPE/DGI')->count())->toBe(4)
        ->and(TaxOffice::query()->whereIn('code', ['DEMO-PLT', 'DEMO-BKE'])->distinct()->count('regional_directorate_id'))->toBe(2)
        ->and(User::query()->where('phone', 'like', '+225070000001_')->orWhere('phone', 'like', '+225070000000_')->count())->toBe(11)
        ->and(Membership::query()->whereHas('user', fn ($q) => $q->where('phone', '+2250700000011'))->value('role'))->toBe(Role::MemberOwner)
        ->and(Membership::query()->whereHas('user', fn ($q) => $q->where('phone', '+2250700000003'))->count())->toBe(3)
        ->and(Member::query()->withoutGlobalScopes()->where('ncc', '9000001T')->where('status', 'radiated')->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(2)
        ->and(Invitation::query()->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(app(SettingsService::class)->get('rate_limit.otp'))->toBe(['max_attempts' => 3, 'decay_minutes' => 1]);
});

it('ouvre la session des comptes de démonstration, et refuse le compte désactivé', function () {
    $this->seed(DatabaseSeeder::class);
    $password = config('cga.seed.demo.password');

    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/login', ['identifier' => '0700000003', 'password' => $password])
        ->assertOk()->assertJsonCount(3, 'data.memberships')->assertJsonPath('data.active_membership_id', null);
    $this->withHeaders(Spa::headers())->postJson('/api/v1/auth/login', ['identifier' => '0700000005', 'password' => $password])
        ->assertForbidden();
    $this->withHeaders(Spa::headers())->getJson('/api/v1/auth/invitations/'.config('cga.seed.demo.invitation_token'))
        ->assertOk()->assertJsonPath('data.first_name', 'Fatou');
    $this->withHeaders(Spa::headers())->getJson('/api/v1/auth/invitations/'.config('cga.seed.demo.revoked_invitation_token'))
        ->assertOk()->assertJsonPath('data.status', 'invalid');
});

it('n\'est pas chargé sans SEED_DEMO', function () {
    config(['cga.seed.demo.enabled' => false]);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
});
