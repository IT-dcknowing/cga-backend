<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Spa;

/*
 * Accueil de l'espace adhérent, version minimale (06-IMPLEMENTATION-PLAN étape 2.11) :
 * GET /api/v1/member/home — son dossier et l'identité de son CGA, jamais l'apporteur (RG-PTF-06).
 */

function homeAccess(Member $member, Role $role = Role::MemberOwner, array $attributes = []): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id, ...$attributes]);
}

it('montre à l\'adhérent son dossier et les coordonnées de son CGA, sans l\'apporteur', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $manager->organization->forceFill(['name' => 'DC-KNOWING CGA', 'phone_1' => '+2252722445501', 'email' => 'contact@dck.ci', 'city' => 'Abidjan'])->save();
    $referrer = app(TenantContext::class)->runAs($manager, fn () => BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]));
    $member = validatedMember($manager, ['company_name' => 'BOULANGERIE ADJOUA', 'membership_number' => '2026-DCK-0001', 'referrer_id' => $referrer->id]);

    $response = inSpace(homeAccess($member))->getJson('/api/v1/member/home')
        ->assertOk()
        ->assertJsonPath('data.member.company_name', 'BOULANGERIE ADJOUA')
        ->assertJsonPath('data.member.number', '2026-DCK-0001')
        ->assertJsonPath('data.member.portfolio', 'cga')
        ->assertJsonPath('data.member.ended_on', null)
        ->assertJsonPath('data.access.role', 'member_owner')
        ->assertJsonPath('data.organization.name', 'DC-KNOWING CGA')
        ->assertJsonPath('data.organization.phone_1', '+2252722445501')
        ->assertJsonPath('data.organization.email', 'contact@dck.ci');

    expect(json_encode($response->json()))->not->toContain($referrer->id)->not->toContain('referrer')
        ->and(ActivityLog::query()->where('action', 'member.home')->exists())->toBeTrue();
});

it('annonce la fin de l\'adhésion après une radiation validée, et la consultation seule', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $member = validatedMember($manager, ['status' => MemberStatus::Radiated]);
    app(TenantContext::class)->runAs($manager, function () use ($member, $manager): void {
        $radiation = new Radiation;
        $radiation->forceFill([
            'organization_id' => $member->organization_id, 'member_id' => $member->id, 'status' => RadiationStatus::Validated,
            'initiator' => RadiationInitiator::Member, 'reason' => RadiationReason::Dissatisfaction, 'effective_date' => '2026-09-30',
            'observation' => 'Lettre du client.', 'requested_by' => $manager->user_id, 'requested_at' => now(), 'validated_by' => $manager->user_id, 'validated_at' => now(),
        ])->save();
    });

    inSpace(homeAccess($member, attributes: ['read_only_until' => '2027-03-31']))->getJson('/api/v1/member/home')
        ->assertOk()
        ->assertJsonPath('data.member.status', 'radiated')
        ->assertJsonPath('data.member.ended_on', '2026-09-30')
        ->assertJsonPath('data.access.read_only_until', '2027-03-31');
});

it('montre le dossier de l\'accès secondaire, et jamais celui d\'un autre adhérent', function () {
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $member = validatedMember($manager, ['company_name' => 'MON DOSSIER']);
    validatedMember($manager, ['company_name' => 'AUTRE DOSSIER']);

    $response = inSpace(homeAccess($member, Role::MemberUser))->getJson('/api/v1/member/home')->assertOk();

    expect($response->json('data.member.company_name'))->toBe('MON DOSSIER')
        ->and(json_encode($response->json()))->not->toContain('AUTRE DOSSIER');
});

it('réserve l\'accueil aux accès adhérents', function (Role $role) {
    inSpace(Membership::factory()->role($role)->create())->getJson('/api/v1/member/home')->assertForbidden();
})->with([
    'gérant' => [Role::CgaManager],
    'comptable' => [Role::CgaAccountant],
    'Présidente du CNCGA' => [Role::CncgaPresident],
]);

it('refuse un visiteur sans session', function () {
    $this->withHeaders(Spa::headers())->getJson('/api/v1/member/home')->assertUnauthorized();
});
