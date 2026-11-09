<?php

use App\Domain\Members\Models\Member;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;

/*
 * Agents à qui affecter des dossiers de la campagne de clôture (/cga/cloture, étape 4.11, F-OBL-08) :
 * les agents actifs du centre, par nom ; ni accès adhérent, ni agent désactivé, ni agent d'un autre
 * centre ; réservé au droit closing.view.
 */

function agentNamed(string $organizationId, string $first, string $last, Role $role = Role::CgaAccountant, MembershipStatus $status = MembershipStatus::Active): Membership
{
    return Membership::factory()->role($role)->create([
        'organization_id' => $organizationId,
        'user_id' => User::factory()->create(['first_name' => $first, 'last_name' => $last])->id,
        'status' => $status,
    ]);
}

it('liste les agents actifs du centre, par nom, sans adhérent, désactivé ni autre centre', function () {
    $manager = agentNamed(Membership::factory()->role(Role::CgaManager)->create()->organization_id, 'Koffi', 'Gérant', Role::CgaManager);
    $cga = $manager->organization_id;
    agentNamed($cga, 'Adjoua', 'Comptable');
    $legal = agentNamed($cga, 'Bamba', 'Juriste', Role::CgaLegal);
    // Deuxième rôle du même agent : une seule ligne.
    Membership::factory()->role(Role::CgaTaxAdvisor)->create(['organization_id' => $cga, 'user_id' => $legal->user_id]);
    agentNamed($cga, 'Zoé', 'Désactivée', status: MembershipStatus::Disabled);
    agentNamed(Membership::factory()->role(Role::CgaManager)->create()->organization_id, 'Autre', 'Centre');
    $member = Member::factory()->active()->create(['organization_id' => $cga]);
    Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $cga, 'member_id' => $member->id]);

    $names = inSpace($manager)->getJson('/api/v1/cga/closing-campaigns/agents')->assertOk()->json('data.*.name');

    expect($names)->toContain('Adjoua Comptable', 'Bamba Juriste', 'Koffi Gérant')
        ->not->toContain('Zoé Désactivée', 'Autre Centre')
        ->and(array_count_values($names)['Bamba Juriste'])->toBe(1)
        ->and($names)->toBe(collect($names)->sort()->values()->all());
    expect(inSpace($manager)->getJson('/api/v1/cga/closing-campaigns/agents')->json('data.0'))->toHaveKeys(['id', 'name']);
});

it('réservé au droit closing.view : ni le CNCGA ni l\'adhérent', function () {
    $member = Member::factory()->active()->create();

    inSpace(Membership::factory()->role(Role::CgaAccountant)->create())->getJson('/api/v1/cga/closing-campaigns/agents')->assertOk();
    inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson('/api/v1/cga/closing-campaigns/agents')->assertForbidden();
    inSpace(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]))
        ->getJson('/api/v1/cga/closing-campaigns/agents')->assertForbidden();
});
