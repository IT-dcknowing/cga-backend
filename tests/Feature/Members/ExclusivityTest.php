<?php

use App\Domain\Members\Actions\AssertMembershipExclusivity;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Concurrency;

/*
 * Exclusivité bloquante (06-IMPLEMENTATION-PLAN étape 2.13, RG-ADH-05, T-22) : un contribuable n'entre
 * au portefeuille CGA que d'un seul CGA, même quand deux CGA valident le même NCC au même instant ;
 * le refus (409) ne nomme jamais l'autre CGA (RG-SEC-02).
 */

function exclusiveManager(string $abbreviation): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['name' => "CGA {$abbreviation}", 'numbering_abbreviation' => $abbreviation])->id,
    ]);
}

/**
 * Brouillon complet du portefeuille CGA, prêt à valider.
 */
function exclusiveDraft(Membership $manager, string $ncc): Member
{
    $member = Member::factory()->create([
        'organization_id' => $manager->organization_id, 'ncc' => $ncc, 'tax_office_id' => TaxOffice::factory()->create()->id,
        'sector_id' => Sector::factory()->create()->id, 'address' => 'Cocody', 'signatory_name' => 'Koffi Kouassi', 'signatory_title' => 'Gérant',
        'cga_contract_signed_on' => '2026-03-01', 'cga_contract_confirmed_by' => $manager->user_id,
    ]);
    app(TenantContext::class)->runAs($manager, function () use ($member): void {
        $revenue = new MemberRevenue;
        $revenue->member_id = $member->id;
        $revenue->year = 2025;
        $revenue->amount = 9_000_000;
        $revenue->setAttribute('kind', 'actual');
        $revenue->setAttribute('source', 'entry');
        $revenue->save();
    });

    return $member;
}

it('RG-ADH-05 refuse la validation d\'un NCC déjà au portefeuille CGA d\'un autre CGA, sans nommer ce CGA', function () {
    $first = exclusiveManager('AAA');
    $second = exclusiveManager('BBB');
    inSpace($first)->postJson('/api/v1/cga/members/'.exclusiveDraft($first, '5555555E')->id.'/validate', ['exclusivity_confirmed' => true])->assertOk();

    $response = inSpace($second)->postJson('/api/v1/cga/members/'.exclusiveDraft($second, '5555555E')->id.'/validate', ['exclusivity_confirmed' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'membership_exclusive')
        ->assertJsonPath('message', __('errors.membership_exclusive'));

    expect((string) $response->getContent())->not->toContain('CGA AAA')->not->toContain('AAA');
});

it('n\'est appelée que dans une transaction : le verrou ne protégerait rien hors d\'elle', function () {
    DB::commit();
    try {
        expect(fn () => app(AssertMembershipExclusivity::class)->execute('5555555E', 'org'))->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});

it('RG-ADH-05 T-22 laisse passer une seule des validations simultanées d\'un même NCC dans deux CGA', function () {
    $first = exclusiveManager('AAA');
    $second = exclusiveManager('BBB');
    $pairs = [];
    foreach (range(1, 5) as $n) {
        $ncc = sprintf('%07dX', 7_000_000 + $n);
        $pairs[] = [$first->id, exclusiveDraft($first, $ncc)->id];
        $pairs[] = [$second->id, exclusiveDraft($second, $ncc)->id];
    }

    $outputs = Concurrency::validate($pairs);

    foreach (array_chunk($outputs, 2) as $index => [$a, $b]) {
        $results = [str_starts_with($a, 'OK ') ? 'OK' : $a, str_starts_with($b, 'OK ') ? 'OK' : $b];
        sort($results);
        expect($results)->toBe(['ERR membership_exclusive', 'OK'], 'NCC n° '.($index + 1).' : '.implode(' / ', [$a, $b]));
    }
})->group('concurrency');
