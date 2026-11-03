<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Importers\RevenueRowImporter;
use App\Domain\Imports\Importers\TaxPaymentRowImporter;
use App\Domain\Imports\Jobs\ProcessImport;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\RevenueSource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationPayment;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reports\Support\PortfolioStates;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
 * Reprise d'historique (06-IMPLEMENTATION-PLAN étape 5.6, F-IMP-03, RG-DATA-06, RG-DATA-07) : chiffres
 * d'affaires par année et paiements d'impôts par nature et par période, importés par les agents du centre,
 * pour les surclassements et le rapport trimestriel. Compta Flow fait foi.
 */

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-11-03 10:00:00');
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->member = Member::factory()->active()->create(['organization_id' => $this->manager->organization_id, 'ncc' => '1234567A']);
});

/**
 * Classeur d'un import d'historique : en-têtes du modèle, puis les lignes par clé de colonne.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function historyWorkbook(RowImporter $importer, array $rows): UploadedFile
{
    $columns = $importer->columns();
    $path = (string) tempnam(sys_get_temp_dir(), 'test-xlsx-');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_map(static fn (array $column): string => $column['label'], array_values($columns))));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues(array_map(static fn (string $key): mixed => $row[$key] ?? null, array_keys($columns))));
    }
    $writer->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('historique.xlsx', $contents);
}

/**
 * Dépose le fichier, puis le traite comme le worker de la file « imports ».
 */
function importHistory(Membership $agent, string $type, UploadedFile $file): ImportBatch
{
    Queue::fake();
    $id = (string) inSpace($agent)->post("/api/v1/cga/imports/{$type}", ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->json('data.id');
    app()->call([Queue::pushed(ProcessImport::class)->last(), 'handle']);

    return app(TenantContext::class)->runAs($agent, fn (): ImportBatch => ImportBatch::query()->with('rows')->findOrFail($id));
}

function messagesOf(ImportBatch $batch): array
{
    return $batch->rows->sortBy('row_number')->mapWithKeys(fn ($row): array => [$row->row_number => $row->messages])->all();
}

describe('chiffres d\'affaires (F-IMP-03)', function () {
    it('importe le CA par année, réalisé ou prévisionnel ; remplace une saisie, jamais une donnée Compta Flow (RG-DATA-06)', function () {
        $other = Member::factory()->active()->create(['organization_id' => $this->manager->organization_id]);
        Tenancy::bypass(function () use ($other): void {
            foreach ([[$this->member, 2024, RevenueSource::Entry, 1_000_000], [$other, 2024, RevenueSource::ComptaFlow, 7_000_000]] as [$member, $year, $source, $amount]) {
                $revenue = new MemberRevenue;
                $revenue->forceFill(['organization_id' => $member->organization_id, 'member_id' => $member->id, 'year' => $year, 'kind' => RevenueKind::Actual, 'amount' => $amount, 'source' => $source])->save();
            }
        }, 'Test.');
        $foreign = Member::factory()->active()->create();

        $batch = importHistory($this->manager, 'revenues', historyWorkbook(app(RevenueRowImporter::class), [
            ['member' => $this->member->membership_number, 'year' => 2023, 'amount' => '12 500 000'],
            ['member' => '1234567a', 'year' => 2024, 'amount' => 15_000_000, 'kind' => 'Réalisé'],
            ['member' => $this->member->ncc, 'year' => 2027, 'amount' => 18_000_000, 'kind' => 'Prévisionnel'],
            ['member' => $other->membership_number, 'year' => 2024, 'amount' => 6_000_000],
            ['member' => $foreign->ncc, 'year' => 2024, 'amount' => 1],
            ['member' => $this->member->ncc, 'year' => 1990, 'amount' => 'beaucoup', 'kind' => 'Estimé'],
        ]));

        expect($batch)->status->toBe(ImportStatus::Done)->imported_rows->toBe(3)->rejected_rows->toBe(3);
        $messages = messagesOf($batch);
        expect($messages[5])->toBe([__('imports.history.compta_flow')])
            ->and($messages[6])->toBe([__('imports.history.unknown_member', ['value' => $foreign->ncc])])
            ->and($messages[7])->toHaveCount(3);

        $revenues = Tenancy::bypass(fn () => MemberRevenue::query()->where('member_id', $this->member->id)->orderBy('year')->get(), 'Test.');
        expect($revenues->map(fn (MemberRevenue $revenue): array => [$revenue->year, $revenue->kind, $revenue->amount, $revenue->source])->all())->toEqual([
            [2023, RevenueKind::Actual, 12_500_000, RevenueSource::Import],
            [2024, RevenueKind::Actual, 15_000_000, RevenueSource::Import],
            [2027, RevenueKind::Forecast, 18_000_000, RevenueSource::Import],
        ])->and(Tenancy::bypass(fn () => MemberRevenue::query()->where('member_id', $other->id)->value('amount'), 'Test.'))->toBe(7_000_000)
            ->and(Tenancy::bypass(fn () => AuditLog::query()->where('subject_type', (new MemberRevenue)->getMorphClass())->where('event', 'updated')->exists(), 'Test.'))->toBeTrue();
    });
});

describe('paiements d\'impôts (F-IMP-03)', function () {
    it('rattache le paiement à l\'échéance de la période, ou crée une échéance d\'historique ; jamais deux fois le même paiement', function () {
        $tee = ObligationInstance::factory()->create([
            'member_obligation_id' => MemberObligation::factory()->create(['member_id' => $this->member->id])->id,
            'kind' => InstanceKind::Payment, 'tax_nature' => TaxNature::Tee, 'period_label' => '2025-T2',
            'period_start' => '2025-04-01', 'period_end' => '2025-06-30', 'due_date' => '2025-07-10', 'amount_due' => 90_000, 'status' => InstanceStatus::ToPay,
        ]);
        $rows = [
            ['member' => $this->member->ncc, 'nature' => 'TEE', 'period' => '2025-T2', 'amount' => 45_000, 'paid_on' => '15/07/2025'],
            ['member' => $this->member->ncc, 'nature' => 'Impôt microentreprise', 'period' => '2024', 'amount' => 300_000, 'paid_on' => '2024-12-20', 'amount_due' => 400_000, 'reference' => 'Q-2024-001'],
            ['member' => $this->member->ncc, 'nature' => 'cnps', 'period' => '03/2025', 'amount' => 25_000, 'paid_on' => '2025-04-10'],
        ];

        $batch = importHistory($this->manager, 'tax-payments', historyWorkbook(app(TaxPaymentRowImporter::class), $rows));
        expect($batch)->imported_rows->toBe(3)->rejected_rows->toBe(0);

        $payments = Tenancy::bypass(fn () => ObligationPayment::query()->with('instance')->get()->keyBy(fn (ObligationPayment $payment): string => $payment->tax_nature->value), 'Test.');
        [$teePayment, $rmePayment, $cnpsPayment] = [$payments['tee'], $payments['rme'], $payments['cnps']];
        expect($teePayment)->source->toBe(ObligationPaymentSource::Import)->status->toBe(PaymentStatus::Confirmed)->instance_id->toBe($tee->id)
            ->and($teePayment->instance)->amount_paid->toBe(45_000)->status->toBe(InstanceStatus::PartiallyPaid)
            ->and($rmePayment->instance)->period_label->toBe('2024')->due_date->toDateString()->toBe('2024-12-31')->amount_due->toBe(400_000)
            ->and($rmePayment->instance->label)->toBe('Impôt microentreprise 2024 (historique importé)')
            ->and($rmePayment->external_ref)->toBe('Q-2024-001')
            ->and($cnpsPayment->instance)->period_label->toBe('2025-03')->amount_due->toBe(25_000)->status->toBe(InstanceStatus::Paid);

        $again = importHistory($this->manager, 'tax-payments', historyWorkbook(app(TaxPaymentRowImporter::class), [
            ...$rows,
            ['member' => $this->member->ncc, 'nature' => 'TEE', 'period' => '2025-T2', 'amount' => 50_000, 'paid_on' => '20/07/2025'],
        ]));
        expect($again)->imported_rows->toBe(0)->rejected_rows->toBe(4)
            ->and(messagesOf($again)[2])->toBe([__('imports.tax_payments.already_imported')])
            ->and(messagesOf($again)[5])->toBe([__('imports.tax_payments.exceeds_balance', ['balance' => 45_000])]);
    });

    it('rejette nature, période, date ou montant illisibles ; un paiement futur ; une échéance réglée par Compta Flow (RG-DATA-06)', function () {
        $instance = ObligationInstance::factory()->create([
            'member_obligation_id' => MemberObligation::factory()->create(['member_id' => $this->member->id])->id,
            'kind' => InstanceKind::Payment, 'tax_nature' => TaxNature::Tva, 'period_label' => '2025-05',
            'period_start' => '2025-05-01', 'period_end' => '2025-05-31', 'due_date' => '2025-05-15', 'amount_due' => 100_000, 'status' => InstanceStatus::PartiallyPaid,
        ]);
        Tenancy::bypass(function () use ($instance): void {
            $payment = new ObligationPayment;
            $payment->forceFill(['organization_id' => $instance->organization_id, 'instance_id' => $instance->id, 'member_id' => $instance->member_id, 'tax_nature' => TaxNature::Tva,
                'amount' => 10_000, 'paid_on' => '2025-05-14', 'source' => ObligationPaymentSource::ComptaFlow, 'external_ref' => 'flow-1', 'status' => PaymentStatus::Confirmed])->save();
        }, 'Test.');

        $batch = importHistory($this->manager, 'tax-payments', historyWorkbook(app(TaxPaymentRowImporter::class), [
            ['member' => $this->member->ncc, 'nature' => 'Taxe inconnue', 'period' => '2025-T5', 'amount' => '12,5', 'paid_on' => '31/02/2025', 'amount_due' => 'zéro'],
            ['member' => $this->member->ncc, 'nature' => 'TVA', 'period' => '2025-12', 'amount' => 1_000, 'paid_on' => '2026-12-01', 'reference' => str_repeat('R', 101)],
            ['member' => $this->member->ncc, 'nature' => 'TVA', 'period' => '2025-05', 'amount' => 1_000, 'paid_on' => '2025-06-01'],
            ['member' => null, 'nature' => 'TVA', 'period' => '2025', 'amount' => 1_000, 'paid_on' => '2025-06-01'],
        ]));

        $messages = messagesOf($batch);
        expect($batch->rejected_rows)->toBe(4)
            ->and($messages[2])->toHaveCount(5)
            ->and($messages[3])->toBe([__('imports.tax_payments.future_date'), __('imports.tax_payments.reference_too_long')])
            ->and($messages[4])->toBe([__('imports.history.compta_flow')])
            ->and($messages[5])->toBe([__('imports.history.required', ['field' => 'N° d\'adhérent ou NCC'])]);
    });
});

describe('modèles, droits et liste des imports', function () {
    it('donne les modèles aux agents qui suivent les obligations ; refusés à la secrétaire et au CNCGA ; fichier non Excel refusé', function () {
        $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $this->manager->organization_id]);
        $secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->manager->organization_id]);

        inSpace($this->manager)->get('/api/v1/cga/imports/revenues/template')->assertOk()->assertDownload('modele-import-historique-ca.xlsx');
        inSpace($accountant)->get('/api/v1/cga/imports/tax-payments/template')->assertOk()->assertDownload('modele-import-historique-paiements.xlsx');
        inSpace($secretary)->get('/api/v1/cga/imports/revenues/template', ['Accept' => 'application/json'])->assertForbidden();
        inSpace($secretary)->post('/api/v1/cga/imports/tax-payments', ['file' => historyWorkbook(app(TaxPaymentRowImporter::class), [])], ['Accept' => 'application/json'])->assertForbidden();
        inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->get('/api/v1/cga/imports/revenues/template', ['Accept' => 'application/json'])->assertForbidden();
        inSpace($this->manager)->post('/api/v1/cga/imports/revenues', ['file' => UploadedFile::fake()->createWithContent('ca.txt', 'pas un classeur')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $batch = importHistory($accountant, 'revenues', historyWorkbook(app(RevenueRowImporter::class), [['member' => $this->member->ncc, 'year' => 2025, 'amount' => 1_000]]));
        inSpace($this->manager)->getJson('/api/v1/cga/imports?filter[type]=revenues')->assertOk()->assertJsonPath('data.0.id', $batch->id);
        inSpace($this->manager)->getJson("/api/v1/cga/imports/{$batch->id}/rows")->assertOk()->assertJsonPath('data.0.status', 'imported');
        inSpace($secretary)->getJson('/api/v1/cga/imports?filter[type]=revenues')->assertForbidden();
        inSpace($accountant)->getJson('/api/v1/cga/imports?filter[type]=members')->assertForbidden();
        inSpace($this->manager)->getJson('/api/v1/cga/imports?filter[type]=cgas')->assertUnprocessable();
    });
});

it('RG-RPT-12 · l\'historique importé alimente les états du rapport, avec sa source « import »', function () {
    importHistory($this->manager, 'revenues', historyWorkbook(app(RevenueRowImporter::class), [['member' => $this->member->ncc, 'year' => 2025, 'amount' => 9_000_000]]));
    importHistory($this->manager, 'tax-payments', historyWorkbook(app(TaxPaymentRowImporter::class), [
        ['member' => $this->member->ncc, 'nature' => 'TEE', 'period' => '2026-T3', 'amount' => 60_000, 'paid_on' => '2026-08-10'],
    ]));

    $states = Tenancy::bypass(fn (): array => app(PortfolioStates::class)->compute($this->manager->organization_id, 2026, 3, ['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net']), 'Test.');

    expect($states['sources'])->toEqual(['revenues' => ['import' => 1], 'payments' => ['import' => 1]]);
});
