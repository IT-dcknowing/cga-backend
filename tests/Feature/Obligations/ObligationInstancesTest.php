<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Onglet Obligations du dossier, saisie du CGA (06-IMPLEMENTATION-PLAN étape 4.4, F-OBL-04) : montant
 * dû avant et après abattement (RG-OBL-08), paiements et statuts déduits (RG-OBL-07), « Marquer faite »
 * (RG-OBL-05), diligences, « + Autre administration », « Total dû » par bloc (RG-OBL-08), règles de la
 * décision 0004, droits par rôle et cloisonnement. Catalogue d'essai : jamais le référentiel de DC-KNOWING.
 */

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-26 10:00:00');
});

function instancesAgent(Role $role = Role::CgaManager, ?Organization $cga = null): Membership
{
    return Membership::factory()->role($role)->create($cga === null ? [] : ['organization_id' => $cga->id]);
}

/**
 * Échéance d'une obligation attribuée à un dossier validé du centre de l'agent.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $type
 */
function memberInstance(Membership $agent, array $attributes = [], array $type = [], ?Member $member = null): ObligationInstance
{
    $member ??= Member::factory()->active()->create(['organization_id' => $agent->organization_id]);
    $obligation = MemberObligation::factory()->create([
        'member_id' => $member->id,
        'obligation_type_id' => ObligationType::factory()->create($type)->id,
    ]);

    return ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function payable(Membership $agent, array $attributes = []): ObligationInstance
{
    return memberInstance($agent, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay, 'label' => 'Paiement d\'essai', ...$attributes]);
}

function instanceUrl(ObligationInstance $instance, string $action = ''): string
{
    return "/api/v1/cga/members/{$instance->member_id}/obligation-instances/{$instance->id}".($action === '' ? '' : "/{$action}");
}

function pdfProof(string $name = 'quittance.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
}

describe('montant dû et paiements (RG-OBL-07, 08)', function () {
    it('RG-OBL-08 · le CGA saisit le montant dû, avant et après abattement pour la TEE ; le reste en découle', function () {
        $agent = instancesAgent();
        $instance = payable($agent);

        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 60_000, 'amount_gross' => 100_000])
            ->assertOk()
            ->assertJsonPath('data.amount_due', 60_000)
            ->assertJsonPath('data.amount_gross', 100_000)
            ->assertJsonPath('data.balance', 60_000)
            ->assertJsonPath('data.status', 'to_pay')
            ->assertJsonPath('data.can.record_payment', true);

        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 0])
            ->assertOk()->assertJsonPath('data.amount_gross', null)->assertJsonPath('data.status', 'paid')->assertJsonPath('data.can.record_payment', false);
    });

    it('RG-OBL-07 · le statut se déduit des paiements confirmés : payé en partie, puis payé', function () {
        $agent = instancesAgent();
        $instance = payable($agent, ['amount_due' => 60_000]);

        inSpace($agent)->post(instanceUrl($instance, 'payments'), ['amount' => 20_000, 'paid_on' => '2026-10-20', 'proof' => pdfProof()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.amount_paid', 20_000)
            ->assertJsonPath('data.balance', 40_000)
            ->assertJsonPath('data.payments.0.source', 'cga_entry')
            ->assertJsonPath('data.payments.0.status', 'confirmed')
            ->assertJsonPath('data.payments.0.proof.name', 'quittance.pdf');

        inSpace($agent)->postJson(instanceUrl($instance, 'payments'), ['amount' => 40_000, 'paid_on' => '2026-10-26'])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0)->assertJsonPath('data.can.record_payment', false);

        expect(Tenancy::bypass(static fn () => ObligationPayment::query()->where('instance_id', $instance->id)->get(), 'Test.'))
            ->toHaveCount(2)
            ->each(fn ($payment) => $payment->recorded_by->toBe($agent->user_id)->confirmed_by->toBe($agent->user_id));
    });

    it('décision 0004 · ni paiement sans montant dû ni au-delà du reste ; le dû ne descend pas sous le payé', function () {
        $agent = instancesAgent();
        $instance = payable($agent);

        inSpace($agent)->postJson(instanceUrl($instance, 'payments'), ['amount' => 5_000, 'paid_on' => '2026-10-20'])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_amount_unknown');

        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 10_000])->assertOk();
        inSpace($agent)->postJson(instanceUrl($instance, 'payments'), ['amount' => 15_000, 'paid_on' => '2026-10-20'])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_payment_exceeds_balance')->assertJsonPath('details.balance', 10_000);

        inSpace($agent)->postJson(instanceUrl($instance, 'payments'), ['amount' => 6_000, 'paid_on' => '2026-10-20'])->assertCreated();
        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 5_000])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_amount_below_paid')->assertJsonPath('details.amount_paid', 6_000);
    });

    it('refuse la saisie sur une déclaration ou une échéance annulée', function () {
        $agent = instancesAgent();
        $declaration = memberInstance($agent);
        $cancelled = payable($agent, ['status' => InstanceStatus::Cancelled, 'amount_due' => 1_000]);

        inSpace($agent)->putJson(instanceUrl($declaration, 'amount'), ['amount_due' => 1_000])->assertStatus(409)->assertJsonPath('code', 'instance_not_payment');
        inSpace($agent)->postJson(instanceUrl($declaration, 'payments'), ['amount' => 1_000, 'paid_on' => '2026-10-20'])->assertStatus(409)->assertJsonPath('code', 'instance_not_payment');
        inSpace($agent)->putJson(instanceUrl($cancelled, 'amount'), ['amount_due' => 1_000])->assertStatus(409)->assertJsonPath('code', 'instance_cancelled');
        inSpace($agent)->postJson(instanceUrl($cancelled, 'payments'), ['amount' => 500, 'paid_on' => '2026-10-20'])->assertStatus(409)->assertJsonPath('code', 'instance_cancelled');
    });

    it('valide les saisies : montants, abattement, date, type de preuve', function () {
        $agent = instancesAgent();
        $instance = payable($agent, ['amount_due' => 10_000]);

        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => -1])->assertUnprocessable()->assertJsonValidationErrors('amount_due');
        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 100, 'amount_gross' => 50])->assertUnprocessable()->assertJsonValidationErrors('amount_gross');
        inSpace($agent)->postJson(instanceUrl($instance, 'payments'), ['amount' => 0, 'paid_on' => '2026-10-27'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'paid_on']);
        inSpace($agent)->post(instanceUrl($instance, 'payments'), ['amount' => 1_000, 'paid_on' => '2026-10-20', 'proof' => UploadedFile::fake()->createWithContent('preuve.txt', 'texte')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
    });
});

describe('déclaration faite, diligences, autre administration', function () {
    it('RG-OBL-05 · « Marquer faite » : la déclaration est faite et le paiement de la période naît avec son montant', function () {
        $agent = instancesAgent();
        $declaration = memberInstance($agent, [], ['kind' => ObligationKind::DeclarationPayment, 'has_abatement' => true]);

        $response = inSpace($agent)->postJson(instanceUrl($declaration, 'done'), ['done_on' => '2026-10-24', 'amount_due' => 60_000, 'amount_gross' => 100_000])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.done_at', '2026-10-24')
            ->assertJsonPath('data.can.mark_done', false)
            ->assertJsonPath('payment_instance.kind', 'payment')
            ->assertJsonPath('payment_instance.parent_instance_id', $declaration->id)
            ->assertJsonPath('payment_instance.amount_due', 60_000)
            ->assertJsonPath('payment_instance.amount_gross', 100_000)
            ->assertJsonPath('payment_instance.status', 'to_pay');
        expect($response->json('payment_instance.period_label'))->toBe($declaration->period_label);

        inSpace($agent)->postJson(instanceUrl($declaration, 'done'), ['done_on' => '2026-10-24'])->assertStatus(409)->assertJsonPath('code', 'instance_already_done');
        inSpace($agent)->postJson(instanceUrl(payable($agent), 'done'), ['done_on' => '2026-10-24'])->assertStatus(409)->assertJsonPath('code', 'instance_not_declaration');
    });

    it('« Marquer faite » sans paiement à créer ; saisies refusées', function () {
        $agent = instancesAgent();
        $declaration = memberInstance($agent);

        inSpace($agent)->postJson(instanceUrl($declaration, 'done'), ['done_on' => '2026-10-27'])->assertUnprocessable()->assertJsonValidationErrors('done_on');
        inSpace($agent)->postJson(instanceUrl($declaration, 'done'), ['done_on' => '2026-10-20', 'amount_gross' => 100])->assertUnprocessable()->assertJsonValidationErrors('amount_due');
        inSpace($agent)->postJson(instanceUrl($declaration, 'done'), ['done_on' => '2026-10-20'])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('payment_instance', null);
    });

    it('avancement d\'une diligence : en cours, puis établie à la date du jour ; seulement sur une diligence', function () {
        $agent = instancesAgent();
        $diligence = memberInstance($agent, ['kind' => InstanceKind::Diligence, 'status' => InstanceStatus::NotEstablished]);

        inSpace($agent)->putJson(instanceUrl($diligence, 'diligence'), ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.done_at', null)->assertJsonPath('data.can.set_diligence', true);
        inSpace($agent)->putJson(instanceUrl($diligence, 'diligence'), ['status' => 'established'])
            ->assertOk()->assertJsonPath('data.status', 'established')->assertJsonPath('data.done_at', '2026-10-26');
        inSpace($agent)->putJson(instanceUrl($diligence, 'diligence'), ['status' => 'not_established'])
            ->assertOk()->assertJsonPath('data.done_at', null);
        inSpace($agent)->putJson(instanceUrl($diligence, 'diligence'), ['status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors('status');
        inSpace($agent)->putJson(instanceUrl(memberInstance($agent), 'diligence'), ['status' => 'established'])->assertStatus(409)->assertJsonPath('code', 'instance_not_diligence');
        inSpace($agent)->putJson(instanceUrl(memberInstance($agent, ['kind' => InstanceKind::Diligence, 'status' => InstanceStatus::Cancelled]), 'diligence'), ['status' => 'established'])
            ->assertStatus(409)->assertJsonPath('code', 'instance_cancelled');
    });

    it('« + Autre administration » : ligne de paiement hors catalogue, payée d\'emblée si rien n\'est dû', function () {
        $agent = instancesAgent();
        $member = Member::factory()->active()->create(['organization_id' => $agent->organization_id]);
        $url = "/api/v1/cga/members/{$member->id}/obligation-instances";

        inSpace($agent)->postJson($url, ['authority' => 'Mairie de Cocody', 'amount_due' => 25_000, 'due_date' => '2026-11-30'])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Mairie de Cocody')
            ->assertJsonPath('data.authority_name', 'Mairie de Cocody')
            ->assertJsonPath('data.category', 'other')
            ->assertJsonPath('data.kind', 'payment')
            ->assertJsonPath('data.tax_nature', 'other')
            ->assertJsonPath('data.period_label', '2026-11-30')
            ->assertJsonPath('data.status', 'to_pay');
        inSpace($agent)->postJson($url, ['authority' => 'Chambre de commerce', 'label' => 'Cotisation consulaire', 'amount_due' => 0, 'due_date' => '2026-12-31'])
            ->assertCreated()->assertJsonPath('data.label', 'Cotisation consulaire')->assertJsonPath('data.status', 'paid');
        inSpace($agent)->postJson($url, ['amount_due' => -5, 'due_date' => '30/11/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['authority', 'amount_due', 'due_date']);
    });
});

describe('onglet Obligations : liste et « Total dû » (RG-OBL-08)', function () {
    it('RG-OBL-08 · Total dû par bloc = somme des restes des échéances affichées, hors annulées ; filtres', function () {
        $agent = instancesAgent();
        $member = Member::factory()->active()->create(['organization_id' => $agent->organization_id]);
        $line = static fn (array $attributes, array $type = []) => memberInstance($agent, $attributes, $type, $member);
        $line(['kind' => InstanceKind::Payment, 'status' => InstanceStatus::PartiallyPaid, 'amount_due' => 100_000, 'amount_paid' => 30_000, 'due_date' => '2026-10-15', 'period_label' => 'A']);
        $line(['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay, 'due_date' => '2026-11-15', 'period_label' => 'B']);
        $line(['kind' => InstanceKind::Declaration, 'status' => InstanceStatus::Todo, 'due_date' => '2026-10-15', 'period_label' => 'C']);
        $line(['kind' => InstanceKind::Payment, 'category' => ObligationCategory::Cnps, 'status' => InstanceStatus::Paid, 'amount_due' => 50_000, 'amount_paid' => 50_000, 'due_date' => '2026-11-15', 'period_label' => 'D']);
        $line(['kind' => InstanceKind::Payment, 'category' => ObligationCategory::Cmu, 'status' => InstanceStatus::Cancelled, 'amount_due' => 20_000, 'due_date' => '2026-12-15', 'period_label' => 'E']);
        $line(['kind' => InstanceKind::Payment, 'category' => ObligationCategory::Other, 'status' => InstanceStatus::ToPay, 'amount_due' => 10_000, 'due_date' => '2027-01-15', 'period_label' => 'F']);
        $url = "/api/v1/cga/members/{$member->id}/obligation-instances";

        $response = inSpace($agent)->getJson($url)
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('summary.tax', ['total_due' => 70_000, 'amounts_to_enter' => 1, 'open' => 3, 'overdue' => 2])
            ->assertJsonPath('summary.cnps', ['total_due' => 0, 'amounts_to_enter' => 0, 'open' => 0, 'overdue' => 0])
            ->assertJsonPath('summary.cmu.total_due', 0)
            ->assertJsonPath('summary.other.total_due', 10_000)
            ->assertJsonPath('data.0.overdue', true)
            ->assertJsonPath('data.0.can.mark_done', true);
        expect(collect($response->json('data'))->pluck('due_date')->all())->toBe(['2026-10-15', '2026-10-15', '2026-11-15', '2026-11-15', '2026-12-15', '2027-01-15']);

        inSpace($agent)->getJson("{$url}?category=cnps")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.tax.total_due', 0);
        inSpace($agent)->getJson("{$url}?from=2026-11-01&to=2026-12-31")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('summary.tax.total_due', 0);
        inSpace($agent)->getJson("{$url}?category=inconnue&from=2026-12-31&to=2026-11-01")->assertUnprocessable()->assertJsonValidationErrors(['category', 'to']);
    });
});

describe('droits et cloisonnement', function () {
    it('chaque rôle du CGA lit l\'onglet ; seuls ceux qui gèrent les obligations saisissent', function (Role $role, bool $manages) {
        $manager = instancesAgent();
        $agent = instancesAgent($role, $manager->organization);
        $instance = payable($manager);

        inSpace($agent)->getJson("/api/v1/cga/members/{$instance->member_id}/obligation-instances")
            ->assertOk()->assertJsonPath('data.0.can.set_amount', $manages);
        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 1_000])->assertStatus($manages ? 200 : 403);
    })->with([
        'gérant' => [Role::CgaManager, true],
        'expert-comptable' => [Role::CgaAccountantExpert, true],
        'comptable' => [Role::CgaAccountant, true],
        'juriste' => [Role::CgaLegal, true],
        'fiscaliste' => [Role::CgaTaxAdvisor, true],
        'chargé des honoraires' => [Role::CgaBillingOfficer, false],
        'formateur' => [Role::CgaTrainer, false],
        'secrétaire' => [Role::CgaSecretary, false],
    ]);

    it('un dossier radié ne se gère plus ; le CNCGA et l\'adhérent n\'y ont pas accès', function () {
        $agent = instancesAgent();
        $radiated = Member::factory()->active()->create(['organization_id' => $agent->organization_id, 'status' => MemberStatus::Radiated]);
        $instance = memberInstance($agent, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay], [], $radiated);

        inSpace($agent)->getJson("/api/v1/cga/members/{$radiated->id}/obligation-instances")->assertOk()->assertJsonPath('data.0.can.set_amount', false);
        inSpace($agent)->putJson(instanceUrl($instance, 'amount'), ['amount_due' => 1_000])->assertForbidden();
        inSpace($agent)->postJson("/api/v1/cga/members/{$radiated->id}/obligation-instances", ['authority' => 'Mairie', 'amount_due' => 1, 'due_date' => '2026-11-30'])->assertForbidden();
        inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson("/api/v1/cga/members/{$radiated->id}/obligation-instances")->assertNotFound();
    });

    it('RG-SEC-02 · l\'échéance d\'un autre dossier ou d\'un autre CGA répond 404', function () {
        $agent = instancesAgent();
        $own = payable($agent);
        $sibling = payable($agent);
        $foreign = payable(instancesAgent());

        inSpace($agent)->putJson("/api/v1/cga/members/{$own->member_id}/obligation-instances/{$sibling->id}/amount", ['amount_due' => 1_000])->assertNotFound();
        inSpace($agent)->putJson(instanceUrl($foreign, 'amount'), ['amount_due' => 1_000])->assertNotFound();
        inSpace($agent)->getJson("/api/v1/cga/members/{$foreign->member_id}/obligation-instances")->assertNotFound();
    });
});
