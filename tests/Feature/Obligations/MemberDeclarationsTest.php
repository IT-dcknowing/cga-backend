<?php

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Déclarations de l'adhérent et leur examen par le CGA (06-IMPLEMENTATION-PLAN étape 4.4, F-OBL-05,
 * RG-OBL-09, décision 0004) : « Signaler comme fait / payé » depuis l'espace de l'adhérent, ligne « à
 * confirmer » visible des deux, confirmation, correction ou rejet motivé par le CGA, qui a le dernier
 * mot ; « Mes obligations » avec « Total dû » (RG-OBL-08). Catalogue d'essai : jamais le référentiel de
 * DC-KNOWING.
 */

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-26 10:00:00');
});

/**
 * Accès en ligne principal d'un dossier validé, et l'agent gérant de son CGA.
 *
 * @return array{0: Membership, 1: Membership}
 */
function declaringMember(): array
{
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id]);

    return [Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]), $manager];
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $type
 */
function lineOf(Membership $access, array $attributes = [], array $type = []): ObligationInstance
{
    $obligation = MemberObligation::factory()->create([
        'member_id' => $access->member_id,
        'obligation_type_id' => ObligationType::factory()->create($type)->id,
    ]);

    return ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, ...$attributes]);
}

function declareUrl(ObligationInstance $line): string
{
    return "/api/v1/member/obligations/{$line->id}/declare";
}

function reviewUrl(ObligationInstance $line, string $decision): string
{
    return "/api/v1/cga/members/{$line->member_id}/obligation-instances/{$line->id}/member-declaration/{$decision}";
}

describe('déclaration par l\'adhérent (RG-OBL-09)', function () {
    it('RG-OBL-09 · « Signaler comme fait » : la ligne passe « déclaré — à confirmer », visible des deux, sans rien changer d\'autre', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access);

        inSpace($access)->postJson(declareUrl($line))
            ->assertOk()
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.member_declared_status', 'done')
            ->assertJsonPath('data.can.declare', false);

        inSpace($manager)->getJson("/api/v1/cga/members/{$line->member_id}/obligation-instances")
            ->assertOk()->assertJsonPath('data.0.member_declared_status', 'done')->assertJsonPath('data.0.can.review_declaration', true);
        inSpace($access)->postJson(declareUrl($line))->assertStatus(409)->assertJsonPath('code', 'member_declaration_pending');
    });

    it('RG-OBL-09 · « Signaler comme payé » : montant, date, preuve facultative ; rien ne compte avant confirmation', function () {
        [$access] = declaringMember();
        $line = lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay, 'amount_due' => 50_000]);

        inSpace($access)->post(declareUrl($line), ['amount' => 20_000, 'paid_on' => '2026-10-25', 'proof' => UploadedFile::fake()->createWithContent('recu.pdf', "%PDF-1.4\n%%EOF")])
            ->assertOk()
            ->assertJsonPath('data.status', 'to_pay')
            ->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.member_declared_status', 'partially_paid')
            ->assertJsonPath('data.payments.0.source', 'member_declaration')
            ->assertJsonPath('data.payments.0.status', 'pending_confirmation')
            ->assertJsonPath('data.payments.0.proof.name', 'recu.pdf');
    });

    it('refuse une déclaration impossible : au-delà du reste dû, ligne faite, payée, annulée, diligence', function () {
        [$access] = declaringMember();
        $payment = ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay, 'amount_due' => 10_000];

        inSpace($access)->postJson(declareUrl(lineOf($access, $payment)), ['amount' => 15_000, 'paid_on' => '2026-10-25'])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_payment_exceeds_balance');
        inSpace($access)->postJson(declareUrl(lineOf($access, ['status' => InstanceStatus::Done])))->assertStatus(409)->assertJsonPath('code', 'instance_already_done');
        inSpace($access)->postJson(declareUrl(lineOf($access, [...$payment, 'status' => InstanceStatus::Paid, 'amount_paid' => 10_000])), ['amount' => 1, 'paid_on' => '2026-10-25'])
            ->assertStatus(409)->assertJsonPath('code', 'instance_already_paid');
        inSpace($access)->postJson(declareUrl(lineOf($access, ['status' => InstanceStatus::Cancelled])))->assertStatus(409)->assertJsonPath('code', 'instance_cancelled');
        inSpace($access)->postJson(declareUrl(lineOf($access, ['kind' => InstanceKind::Diligence, 'status' => InstanceStatus::NotEstablished])))
            ->assertStatus(409)->assertJsonPath('code', 'instance_not_declarable');
    });

    it('valide le paiement déclaré : montant et date obligatoires, date passée, preuve PDF ou image', function () {
        [$access] = declaringMember();
        $line = lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay]);

        inSpace($access)->postJson(declareUrl($line), [])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'paid_on']);
        inSpace($access)->postJson(declareUrl($line), ['amount' => 1_000, 'paid_on' => '2026-10-27'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        inSpace($access)->post(declareUrl($line), ['amount' => 1_000, 'paid_on' => '2026-10-25', 'proof' => UploadedFile::fake()->createWithContent('recu.txt', 'texte')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
    });

    it('« Mes obligations » : les lignes du dossier, sans les annulées, avec leur « Total dû » (RG-OBL-08)', function () {
        [$access] = declaringMember();
        lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::PartiallyPaid, 'amount_due' => 80_000, 'amount_paid' => 30_000, 'period_label' => 'A']);
        lineOf($access, ['status' => InstanceStatus::Todo, 'period_label' => 'B']);
        lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::Cancelled, 'amount_due' => 5_000, 'period_label' => 'C']);
        [$other] = declaringMember();
        lineOf($other);

        inSpace($access)->getJson('/api/v1/member/obligations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.tax.total_due', 50_000)
            ->assertJsonPath('data.0.can.declare', true)
            ->assertJsonMissingPath('data.0.can.set_amount');
    });
});

describe('examen par le CGA (RG-OBL-09, décision 0004)', function () {
    it('RG-OBL-09 · confirme une déclaration faite à la date de l\'adhérent ; le paiement de la période naît (RG-OBL-05)', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access, [], ['kind' => ObligationKind::DeclarationPayment]);
        inSpace($access)->postJson(declareUrl($line))->assertOk();
        // Déclaration signalée six jours plus tôt.
        Tenancy::bypass(static fn () => ObligationInstance::query()->whereKey($line->id)->update(['member_declared_at' => '2026-10-20 09:00:00+00']), 'Test : déclaration antérieure.');

        inSpace($manager)->postJson(reviewUrl($line, 'confirm'), ['amount_due' => 45_000])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.done_at', '2026-10-20')
            ->assertJsonPath('data.member_declared_status', null);
        $payment = Tenancy::bypass(static fn () => ObligationInstance::query()->where('parent_instance_id', $line->id)->sole(), 'Test.');
        expect($payment->amount_due)->toBe(45_000)->and($payment->status)->toBe(InstanceStatus::ToPay);
    });

    it('RG-OBL-09 · le CGA corrige le paiement déclaré (montant, date) et le confirme ; il compte alors (RG-OBL-07)', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay, 'amount_due' => 50_000]);
        inSpace($access)->postJson(declareUrl($line), ['amount' => 20_000, 'paid_on' => '2026-10-25'])->assertOk();

        inSpace($manager)->postJson(reviewUrl($line, 'confirm'), ['amount' => 50_000, 'paid_on' => '2026-10-24'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', 50_000)
            ->assertJsonPath('data.member_declared_status', null)
            ->assertJsonPath('data.payments.0.status', 'confirmed')
            ->assertJsonPath('data.payments.0.amount', 50_000)
            ->assertJsonPath('data.payments.0.paid_on', '2026-10-24');
        expect(Tenancy::bypass(static fn () => ObligationPayment::query()->where('instance_id', $line->id)->sole(), 'Test.')->confirmed_by)->toBe($manager->user_id);

        inSpace($manager)->postJson(reviewUrl($line, 'confirm'))->assertStatus(409)->assertJsonPath('code', 'member_declaration_missing');
    });

    it('décision 0004 · montant dû inconnu : le CGA le saisit en confirmant ; jamais au-delà du reste', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay]);
        inSpace($access)->postJson(declareUrl($line), ['amount' => 30_000, 'paid_on' => '2026-10-25'])
            ->assertOk()->assertJsonPath('data.member_declared_status', 'paid');

        inSpace($manager)->postJson(reviewUrl($line, 'confirm'))->assertStatus(409)->assertJsonPath('code', 'obligation_amount_unknown');
        inSpace($manager)->postJson(reviewUrl($line, 'confirm'), ['amount_due' => 20_000])->assertStatus(409)->assertJsonPath('code', 'obligation_payment_exceeds_balance');
        inSpace($manager)->postJson(reviewUrl($line, 'confirm'), ['amount_due' => 40_000])
            ->assertOk()->assertJsonPath('data.amount_due', 40_000)->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.balance', 10_000);
    });

    it('décision 0004 · rejet motivé : la ligne revient à faire ou à payer, l\'adhérent voit le motif et peut redéclarer', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access, ['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay, 'amount_due' => 50_000]);
        inSpace($access)->postJson(declareUrl($line), ['amount' => 20_000, 'paid_on' => '2026-10-25'])->assertOk();

        inSpace($manager)->postJson(reviewUrl($line, 'reject'), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        inSpace($manager)->postJson(reviewUrl($line, 'reject'), ['reason' => 'Aucun paiement reçu par la DGI'])
            ->assertOk()
            ->assertJsonPath('data.status', 'to_pay')
            ->assertJsonPath('data.member_declared_status', null)
            ->assertJsonPath('data.member_declaration_rejection', 'Aucun paiement reçu par la DGI')
            ->assertJsonPath('data.payments.0.status', 'rejected');
        inSpace($manager)->postJson(reviewUrl($line, 'reject'), ['reason' => 'Encore'])->assertStatus(409)->assertJsonPath('code', 'member_declaration_missing');

        inSpace($access)->getJson('/api/v1/member/obligations')->assertJsonPath('data.0.member_declaration_rejection', 'Aucun paiement reçu par la DGI');
        inSpace($access)->postJson(declareUrl($line), ['amount' => 50_000, 'paid_on' => '2026-10-26'])
            ->assertOk()->assertJsonPath('data.member_declared_status', 'paid')->assertJsonPath('data.member_declaration_rejection', null);
    });

    it('valide les corrections du CGA', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access);
        inSpace($access)->postJson(declareUrl($line))->assertOk();

        inSpace($manager)->postJson(reviewUrl($line, 'confirm'), ['done_on' => '2026-10-27', 'amount' => 0, 'amount_gross' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['done_on', 'amount', 'amount_due']);
    });
});

describe('droits et cloisonnement', function () {
    it('RG-SEC-02 · l\'adhérent ne voit ni ne déclare la ligne d\'un autre dossier (404) ; un agent n\'a pas d\'espace adhérent', function () {
        [$access, $manager] = declaringMember();
        $sibling = Member::factory()->active()->create(['organization_id' => $access->organization_id]);
        $foreign = lineOf(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $access->organization_id, 'member_id' => $sibling->id]));

        inSpace($access)->postJson(declareUrl($foreign))->assertNotFound()->assertJsonPath('code', 'not_found');
        inSpace($manager)->getJson('/api/v1/member/obligations')->assertForbidden();
        inSpace($manager)->postJson(declareUrl($foreign))->assertForbidden();
    });

    it('un adhérent radié (consultation seule) ne voit plus ses obligations ; seuls ceux qui gèrent examinent', function () {
        [$access, $manager] = declaringMember();
        $line = lineOf($access);
        inSpace($access)->postJson(declareUrl($line))->assertOk();
        $trainer = Membership::factory()->role(Role::CgaTrainer)->create(['organization_id' => $manager->organization_id]);

        inSpace($trainer)->postJson(reviewUrl($line, 'confirm'))->assertForbidden();
        inSpace($trainer)->postJson(reviewUrl($line, 'reject'), ['reason' => 'Non'])->assertForbidden();

        Tenancy::bypass(static fn () => $access->forceFill(['status' => MembershipStatus::ReadOnly])->save(), 'Test : adhérent radié.');
        inSpace($access)->getJson('/api/v1/member/obligations')->assertForbidden();
    });
});
