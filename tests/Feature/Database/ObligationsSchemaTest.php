<?php

use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\CenterObligationKind;
use App\Domain\Obligations\Enums\CenterObligationStatus;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\CenterObligation;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Tables des obligations (06-IMPLEMENTATION-PLAN étape 4.1, 05-BACKEND-SCHEMA §4 et §7) : contraintes
 * de la base — valeurs permises, unicité du code par version et par CGA, une obligation ouverte par
 * dossier, une échéance par obligation, nature et période (idempotence, RG-OBL-04), paiements.
 */

function refusedByObligationsSchema(Closure $insert): void
{
    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
}

function obligationTypeRow(array $overrides = []): array
{
    return [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => null, 'catalog_version' => '2026.1', 'code' => 'CODE-1',
        'label' => 'Obligation', 'category' => 'tax', 'authority' => 'DGI', 'kind' => 'declaration', 'periodicity' => 'monthly',
        'due_rule' => json_encode(['type' => 'day_of_next_month', 'day' => 15]), 'applicability' => json_encode(['regimes' => []]),
        'data_status' => 'provisional', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
}

it('garde un catalogue cohérent : valeurs permises, un code par version au national et par CGA', function () {
    $cga = Organization::factory()->create();

    DB::table('obligation_types')->insert(obligationTypeRow());
    DB::table('obligation_types')->insert(obligationTypeRow(['catalog_version' => '2026.2']));
    DB::table('obligation_types')->insert(obligationTypeRow(['organization_id' => $cga->id]));

    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow()));
    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow(['organization_id' => $cga->id])));
    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow(['code' => 'C2', 'category' => 'impots'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow(['code' => 'C3', 'kind' => 'autre'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow(['code' => 'C4', 'periodicity' => 'weekly'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow(['code' => 'C5', 'tax_nature' => 'impot'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_types')->insert(obligationTypeRow(['code' => 'C6', 'data_status' => 'draft'])));

    expect(DB::table('obligation_types')->count())->toBe(3);
});

it('RG-OBL-02 · une seule obligation ouverte par dossier ; une obligation retirée peut être rattribuée', function () {
    $obligation = MemberObligation::factory()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $obligation->organization_id, 'member_id' => $obligation->member_id,
        'obligation_type_id' => $obligation->obligation_type_id, 'source' => 'manual', 'status' => 'active', 'start_date' => '2026-10-01',
        'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];

    refusedByObligationsSchema(fn () => DB::table('member_obligations')->insert($row()));
    refusedByObligationsSchema(fn () => DB::table('member_obligations')->insert($row(['status' => 'removed', 'source' => 'imported'])));
    refusedByObligationsSchema(fn () => DB::table('member_obligations')->insert($row(['status' => 'removed', 'end_date' => '2026-09-01'])));

    Tenancy::bypass(static function () use ($obligation): void {
        $obligation->status = MemberObligationStatus::Removed;
        $obligation->end_date = today();
        $obligation->save();
    }, 'Test du schéma : obligation retirée.');
    DB::table('member_obligations')->insert($row());

    expect(DB::table('member_obligations')->where('member_id', $obligation->member_id)->count())->toBe(2);
});

it('RG-OBL-04 · une échéance par obligation, nature et période ; montants et statuts contrôlés', function () {
    $instance = ObligationInstance::factory()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $instance->organization_id, 'member_id' => $instance->member_id,
        'member_obligation_id' => $instance->member_obligation_id, 'obligation_type_id' => $instance->obligation_type_id,
        'label' => 'Échéance', 'category' => 'tax', 'kind' => 'declaration', 'period_label' => $instance->period_label,
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-11-15', 'status' => 'todo',
        'amount_paid' => 0, 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];

    refusedByObligationsSchema(fn () => DB::table('obligation_instances')->insert($row()));
    DB::table('obligation_instances')->insert($row(['kind' => 'payment', 'status' => 'to_pay']));
    // Ligne « autre administration » saisie à la main : pas d'obligation attribuée, pas d'unicité.
    DB::table('obligation_instances')->insert($row(['member_obligation_id' => null, 'obligation_type_id' => null, 'category' => 'other']));
    DB::table('obligation_instances')->insert($row(['member_obligation_id' => null, 'obligation_type_id' => null, 'category' => 'other']));

    refusedByObligationsSchema(fn () => DB::table('obligation_instances')->insert($row(['period_label' => 'X1', 'status' => 'pending'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_instances')->insert($row(['period_label' => 'X2', 'kind' => 'declaration_payment'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_instances')->insert($row(['period_label' => 'X3', 'period_end' => '2026-09-30'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_instances')->insert($row(['period_label' => 'X4', 'amount_due' => -1])));
    refusedByObligationsSchema(fn () => DB::table('obligation_instances')->insert($row(['period_label' => 'X5', 'member_declared_status' => 'maybe'])));

    expect(DB::table('obligation_instances')->where('member_id', $instance->member_id)->count())->toBe(4);
});

it('garde des paiements d\'échéance cohérents : montant positif, Compta Flow confirmé et référencé, sans doublon', function () {
    $instance = ObligationInstance::factory()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $instance->organization_id, 'instance_id' => $instance->id,
        'member_id' => $instance->member_id, 'tax_nature' => 'tva', 'amount' => 50_000, 'paid_on' => '2026-10-20',
        'source' => 'cga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];

    DB::table('obligation_payments')->insert($row());
    DB::table('obligation_payments')->insert($row(['source' => 'compta_flow', 'external_ref' => 'CF-1']));

    refusedByObligationsSchema(fn () => DB::table('obligation_payments')->insert($row(['amount' => 0])));
    refusedByObligationsSchema(fn () => DB::table('obligation_payments')->insert($row(['source' => 'compta_flow', 'external_ref' => 'CF-1'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_payments')->insert($row(['source' => 'compta_flow', 'external_ref' => 'CF-2', 'status' => 'pending_confirmation'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_payments')->insert($row(['source' => 'compta_flow', 'external_ref' => null])));
    refusedByObligationsSchema(fn () => DB::table('obligation_payments')->insert($row(['source' => 'banque'])));
    refusedByObligationsSchema(fn () => DB::table('obligation_payments')->insert($row(['tax_nature' => 'impot'])));

    expect(DB::table('obligation_payments')->count())->toBe(2);
});

it('garde des obligations du centre cohérentes : nature, statut, montant', function () {
    $cga = Organization::factory()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $cga->id, 'label' => 'CNPS du centre', 'kind' => 'cnps',
        'due_date' => '2026-11-15', 'status' => 'todo', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];

    DB::table('center_obligations')->insert($row());
    DB::table('center_obligations')->insert($row(['kind' => 'agreement_renewal', 'amount' => 0, 'status' => 'paid']));

    refusedByObligationsSchema(fn () => DB::table('center_obligations')->insert($row(['kind' => 'loyer'])));
    refusedByObligationsSchema(fn () => DB::table('center_obligations')->insert($row(['status' => 'late'])));
    refusedByObligationsSchema(fn () => DB::table('center_obligations')->insert($row(['amount' => -5])));

    expect(DB::table('center_obligations')->count())->toBe(2);
});

it('relie une échéance à son dossier, son obligation, son type et ses paiements', function () {
    $instance = ObligationInstance::factory()->create();

    Tenancy::bypass(static function () use ($instance): void {
        expect($instance->member)->toBeInstanceOf(Member::class)
            ->and($instance->memberObligation)->toBeInstanceOf(MemberObligation::class)
            ->and($instance->type)->toBeInstanceOf(ObligationType::class)
            ->and($instance->memberObligation->instances()->count())->toBe(1)
            ->and($instance->memberObligation->type->is($instance->type))->toBeTrue()
            ->and($instance->payments()->count())->toBe(0)
            ->and($instance->parent)->toBeNull()
            ->and($instance->balance())->toBeNull();

        $instance->amount_due = 10_000;
        $instance->amount_paid = 4_000;
        expect($instance->balance())->toBe(6_000);
    }, 'Test du schéma : relations d\'une échéance.');
});

it('relie un paiement à son échéance, son dossier et sa preuve ; lit une obligation du centre', function () {
    $instance = ObligationInstance::factory()->create(['kind' => InstanceKind::Payment, 'status' => InstanceStatus::ToPay]);

    Tenancy::bypass(static function () use ($instance): void {
        $proof = app(StorageService::class)->store("%PDF-1.4\n%%EOF", 'quittance.pdf', 'obligations', $instance->organization_id);
        $payment = new ObligationPayment;
        $payment->organization_id = $instance->organization_id;
        $payment->instance_id = $instance->id;
        $payment->member_id = $instance->member_id;
        $payment->tax_nature = TaxNature::Tva;
        $payment->amount = 5_000;
        $payment->paid_on = CarbonImmutable::parse('2026-10-15');
        $payment->source = ObligationPaymentSource::CgaEntry;
        $payment->status = PaymentStatus::Confirmed;
        $payment->proof_file_id = $proof->id;
        $payment->save();

        $centre = new CenterObligation;
        $centre->organization_id = $instance->organization_id;
        $centre->label = 'CNPS du centre';
        $centre->kind = CenterObligationKind::Cnps;
        $centre->due_date = CarbonImmutable::parse('2026-11-15');
        $centre->amount = 120_000;
        $centre->status = CenterObligationStatus::ToPay;
        $centre->save();

        $payment = ObligationPayment::query()->with(['instance', 'member', 'proof'])->findOrFail($payment->id);
        expect($payment->instance->is($instance))->toBeTrue()
            ->and($payment->member->is($instance->member))->toBeTrue()
            ->and($payment->proof?->original_name)->toBe('quittance.pdf')
            ->and($payment->paid_on->toDateString())->toBe('2026-10-15')
            ->and($instance->payments()->sole()->is($payment))->toBeTrue()
            ->and(CenterObligation::query()->sole())
            ->kind->toBe(CenterObligationKind::Cnps)
            ->status->toBe(CenterObligationStatus::ToPay)
            ->amount->toBe(120_000);
    }, 'Test du schéma : paiement d\'échéance et obligation du centre.');
});
