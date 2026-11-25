<?php

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\CenterObligation;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;

/*
 * Planning des échéances du portefeuille et obligations propres du CGA (06-IMPLEMENTATION-PLAN étape
 * 4.4, F-OBL-06, F-OBL-07) : lignes « date — obligation — n adhérents » avec faits / à faire, filtres,
 * indicateur « Échéances sous n jours » (paramétrable), adhérents concernés ; liste, ajout et
 * modification des obligations du centre ; droits et cloisonnement. Jour de référence : 26/10/2026.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 10:00:00');
});

/**
 * Échéance d'un dossier du centre pour une obligation du catalogue d'essai.
 *
 * @param  array<string, mixed>  $attributes
 */
function planned(Organization $cga, ObligationType $type, array $attributes = [], string $company = 'Ets Essai'): ObligationInstance
{
    $member = Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => $company]);
    $obligation = MemberObligation::factory()->create(['member_id' => $member->id, 'obligation_type_id' => $type->id]);

    return ObligationInstance::factory()->create([
        'member_obligation_id' => $obligation->id,
        'member_id' => $member->id,
        'organization_id' => $cga->id,
        'obligation_type_id' => $type->id,
        'label' => $type->label,
        'category' => $type->category,
        'platform' => $type->platform,
        'kind' => InstanceKind::Payment,
        'status' => InstanceStatus::ToPay,
        'due_date' => '2026-11-16',
        ...$attributes,
    ]);
}

describe('planning des échéances (F-OBL-06)', function () {
    it('regroupe par date, obligation et nature : adhérents, faits et à faire ; jusqu\'à l\'horizon par défaut', function () {
        $agent = Membership::factory()->role(Role::CgaAccountant)->create();
        $cga = $agent->organization;
        $cnps = ObligationType::factory()->create(['label' => 'CNPS d\'essai', 'category' => ObligationCategory::Cnps, 'platform' => 'e-CNPS']);
        $tva = ObligationType::factory()->create(['label' => 'TVA d\'essai']);
        planned($cga, $cnps, [], 'Ets Bêta');
        planned($cga, $cnps, ['status' => InstanceStatus::PartiallyPaid], 'Ets Alpha');
        planned($cga, $cnps, ['status' => InstanceStatus::Paid], 'Ets Gamma');
        planned($cga, $cnps, ['status' => InstanceStatus::Cancelled]);
        planned($cga, $tva, ['kind' => InstanceKind::Declaration, 'status' => InstanceStatus::Done]);
        planned($cga, $tva, ['due_date' => '2026-11-05', 'kind' => InstanceKind::Declaration, 'status' => InstanceStatus::Todo]);
        planned($cga, $tva, ['due_date' => '2027-03-01']);
        planned(Organization::factory()->create(), $cnps);

        $response = inSpace($agent)->getJson('/api/v1/cga/obligation-planning')
            ->assertOk()
            ->assertJsonPath('meta.from', '2026-10-26')
            ->assertJsonPath('meta.to', '2027-01-26')
            ->assertJsonPath('meta.upcoming', ['days' => 15, 'count' => 1, 'members' => 1])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0', [
                'due_date' => '2026-11-05', 'obligation_type_id' => $tva->id, 'label' => 'TVA d\'essai', 'category' => 'tax', 'category_label' => 'Impôts et taxes',
                'kind' => 'declaration', 'kind_label' => 'Déclaration', 'platform' => 'e-impôts', 'members' => 1, 'done' => 0, 'open' => 1,
            ]);
        expect($response->json('data.1'))->toMatchArray(['due_date' => '2026-11-16', 'label' => 'CNPS d\'essai', 'kind' => 'payment', 'platform' => 'e-CNPS', 'members' => 3, 'done' => 1, 'open' => 2])
            ->and($response->json('data.2'))->toMatchArray(['label' => 'TVA d\'essai', 'kind' => 'declaration', 'members' => 1, 'done' => 1, 'open' => 0]);

        inSpace($agent)->getJson('/api/v1/cga/obligation-planning?progress=open')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.members', 2);
        inSpace($agent)->getJson('/api/v1/cga/obligation-planning?progress=done')->assertOk()->assertJsonCount(2, 'data');
        inSpace($agent)->getJson("/api/v1/cga/obligation-planning?obligation_type_id={$tva->id}&from=2026-11-01&to=2027-03-31")
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.2.due_date', '2027-03-01');
        inSpace($agent)->getJson('/api/v1/cga/obligation-planning?from=2026-12-31&to=2026-11-01&progress=tout&obligation_type_id=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['to', 'progress', 'obligation_type_id']);
    });

    it('« Échéances sous n jours » : n est un paramètre du réseau, ajustable par le CGA', function () {
        $agent = Membership::factory()->role(Role::CgaManager)->create();
        $type = ObligationType::factory()->create();
        planned($agent->organization, $type, ['due_date' => '2026-11-05']);
        planned($agent->organization, $type, ['due_date' => '2026-11-20']);

        inSpace($agent)->getJson('/api/v1/cga/obligation-planning')->assertOk()->assertJsonPath('meta.upcoming.count', 1);
        app(SettingsService::class)->set('obligations.upcoming_days', 30, $agent->organization_id);
        inSpace($agent)->getJson('/api/v1/cga/obligation-planning')->assertOk()->assertJsonPath('meta.upcoming', ['days' => 30, 'count' => 2, 'members' => 2]);
    });

    it('liste les adhérents d\'une ligne, par raison sociale ; ligne hors catalogue par son libellé', function () {
        $agent = Membership::factory()->role(Role::CgaSecretary)->create();
        $type = ObligationType::factory()->create(['label' => 'CNPS d\'essai']);
        planned($agent->organization, $type, [], 'Ets Bêta');
        planned($agent->organization, $type, ['status' => InstanceStatus::Paid, 'amount_due' => 5_000, 'amount_paid' => 5_000], 'Ets Alpha');
        planned($agent->organization, $type, ['kind' => InstanceKind::Declaration, 'status' => InstanceStatus::Todo]);
        $free = planned($agent->organization, $type, ['obligation_type_id' => null, 'member_obligation_id' => null, 'label' => 'Mairie de Cocody', 'category' => ObligationCategory::Other], 'Ets Delta');
        $query = http_build_query(['due_date' => '2026-11-16', 'kind' => 'payment', 'obligation_type_id' => $type->id, 'label' => 'CNPS d\'essai']);

        $response = inSpace($agent)->getJson("/api/v1/cga/obligation-planning/members?{$query}")->assertOk()->assertJsonCount(2, 'data');
        expect(collect($response->json('data'))->pluck('member.company_name')->all())->toBe(['Ets Alpha', 'Ets Bêta'])
            ->and($response->json('data.0'))->toMatchArray(['status' => 'paid', 'balance' => 0]);

        inSpace($agent)->getJson('/api/v1/cga/obligation-planning/members?'.http_build_query(['due_date' => '2026-11-16', 'kind' => 'payment', 'label' => 'Mairie de Cocody']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.instance_id', $free->id);
        inSpace($agent)->getJson('/api/v1/cga/obligation-planning/members?kind=autre')
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'kind', 'label']);
    });

    it('chaque rôle du CGA consulte le planning ; ni le CNCGA ni l\'adhérent', function (Role $role) {
        inSpace(Membership::factory()->role($role)->create())->getJson('/api/v1/cga/obligation-planning')->assertOk();
    })->with([Role::CgaManager, Role::CgaAccountantExpert, Role::CgaAccountant, Role::CgaLegal, Role::CgaTaxAdvisor, Role::CgaBillingOfficer, Role::CgaTrainer, Role::CgaSecretary]);

    it('refuse le planning hors d\'un espace CGA', function () {
        $member = Member::factory()->active()->create();
        inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson('/api/v1/cga/obligation-planning')->assertForbidden();
        inSpace(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]))
            ->getJson('/api/v1/cga/obligation-planning/members?due_date=2026-11-16&kind=payment&label=X')->assertForbidden();
    });
});

describe('obligations du centre (F-OBL-07)', function () {
    it('le gérant ajoute, liste par échéance et modifie les obligations de son centre', function () {
        $manager = Membership::factory()->role(Role::CgaManager)->create();
        CenterObligation::factory()->create(['organization_id' => $manager->organization_id, 'label' => 'Impôts du centre', 'kind' => 'tax', 'due_date' => '2026-10-15', 'status' => 'to_pay']);
        CenterObligation::factory()->create(['label' => 'Autre centre']);

        $id = inSpace($manager)->postJson('/api/v1/cga/center-obligations', [
            'label' => 'Renouvellement de l\'agrément', 'kind' => 'agreement_renewal', 'due_date' => '2027-03-31', 'amount' => null, 'status' => 'todo', 'notes' => 'Dossier au SP-CGA',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'agreement_renewal')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.overdue', false)
            ->assertJsonPath('data.notes', 'Dossier au SP-CGA')
            ->json('data.id');

        inSpace($manager)->getJson('/api/v1/cga/center-obligations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Impôts du centre')
            ->assertJsonPath('data.0.overdue', true)
            ->assertJsonPath('data.1.id', $id);

        inSpace($manager)->putJson("/api/v1/cga/center-obligations/{$id}", [
            'label' => 'Renouvellement de l\'agrément', 'kind' => 'agreement_renewal', 'due_date' => '2027-03-31', 'amount' => 50_000, 'status' => 'done', 'notes' => '',
        ])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.amount', 50_000)->assertJsonPath('data.notes', null);
    });

    it('valide la saisie', function () {
        inSpace(Membership::factory()->role(Role::CgaManager)->create())->postJson('/api/v1/cga/center-obligations', [
            'label' => '', 'kind' => 'autre', 'due_date' => '31/03/2027', 'amount' => -1, 'status' => 'fini',
        ])->assertUnprocessable()->assertJsonValidationErrors(['label', 'kind', 'due_date', 'amount', 'status']);
    });

    it('réservé au gérant et à l\'expert-comptable (internal.manage)', function (Role $role, bool $allowed) {
        $agent = Membership::factory()->role($role)->create();

        inSpace($agent)->getJson('/api/v1/cga/center-obligations')->assertStatus($allowed ? 200 : 403);
        inSpace($agent)->postJson('/api/v1/cga/center-obligations', ['label' => 'CNPS', 'kind' => 'cnps', 'due_date' => '2026-11-15', 'status' => 'to_pay'])
            ->assertStatus($allowed ? 201 : 403);
    })->with([
        'gérant' => [Role::CgaManager, true],
        'expert-comptable' => [Role::CgaAccountantExpert, true],
        'comptable' => [Role::CgaAccountant, false],
        'juriste' => [Role::CgaLegal, false],
        'fiscaliste' => [Role::CgaTaxAdvisor, false],
        'chargé des honoraires' => [Role::CgaBillingOfficer, false],
        'formateur' => [Role::CgaTrainer, false],
        'secrétaire' => [Role::CgaSecretary, false],
        'CNCGA' => [Role::CncgaManager, false],
    ]);

    it('RG-SEC-02 · l\'obligation d\'un autre centre répond 404', function () {
        $manager = Membership::factory()->role(Role::CgaManager)->create();
        $foreign = CenterObligation::factory()->create();

        inSpace($manager)->putJson("/api/v1/cga/center-obligations/{$foreign->id}", ['label' => 'X', 'kind' => 'tax', 'due_date' => '2026-11-15', 'status' => 'todo'])->assertNotFound();
    });
});
