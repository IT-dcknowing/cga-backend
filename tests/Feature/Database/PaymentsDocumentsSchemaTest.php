<?php

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\DocumentVerification;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonInterface;
use Database\Seeders\TemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Tables des paiements et des documents (06-IMPLEMENTATION-PLAN étape 3.1, 05-BACKEND-SCHEMA §6, §8) :
 * contraintes de la base et chargement des modèles provisoires.
 */

beforeEach(function () {
    Storage::fake();
});

function refusedByPaymentsSchema(Closure $insert): void
{
    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
}

it('garde des sommes dues cohérentes : montants, période des honoraires, une échéance par période, un droit d\'adhésion en cours', function () {
    $member = Member::factory()->active()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $member->organization_id, 'member_id' => $member->id,
        'kind' => 'fees', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-10-10', 'amount' => 17_700,
        'amount_excl_tax' => 15_000, 'tax_rate' => 18, 'tax_amount' => 2_700,
        'status' => 'due', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
    DB::table('member_charges')->insert($row());
    DB::table('member_charges')->insert($row(['kind' => 'entry_fee', 'period_start' => null, 'period_end' => null]));
    DB::table('member_charges')->insert($row(['kind' => 'entry_fee', 'period_start' => null, 'period_end' => null, 'status' => 'cancelled']));

    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row()));
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['kind' => 'entry_fee', 'period_start' => null, 'period_end' => null])));
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['period_start' => '2026-11-01', 'period_end' => null])));
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['period_start' => '2026-12-01', 'amount_paid' => 20_000])));
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['period_start' => '2027-01-01', 'kind' => 'autre'])));
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['period_start' => '2027-02-01', 'status' => 'perdue'])));
    // Montant dû = hors taxe + TVA (décision du 23/10/2026).
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['period_start' => '2027-03-01', 'tax_amount' => 1_000])));
    refusedByPaymentsSchema(fn () => DB::table('member_charges')->insert($row(['period_start' => '2027-04-01', 'tax_rate' => 101])));
});

it('exige un montant positif, la preuve d\'un paiement déclaré par l\'adhérent et le motif d\'un rejet', function () {
    $member = Member::factory()->active()->create();
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $member->organization_id, 'member_id' => $member->id, 'amount' => 25_000,
        'paid_on' => '2026-10-15', 'method' => 'cash', 'source' => 'cga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
    DB::table('member_payments')->insert($row(['receipt_number' => 'REC-0001']));

    refusedByPaymentsSchema(fn () => DB::table('member_payments')->insert($row(['amount' => 0])));
    refusedByPaymentsSchema(fn () => DB::table('member_payments')->insert($row(['source' => 'member_declaration', 'status' => 'pending_confirmation'])));
    refusedByPaymentsSchema(fn () => DB::table('member_payments')->insert($row(['status' => 'rejected'])));
    refusedByPaymentsSchema(fn () => DB::table('member_payments')->insert($row(['receipt_number' => 'REC-0001'])));
    refusedByPaymentsSchema(fn () => DB::table('member_payments')->insert($row(['method' => 'troc'])));

    $payment = MemberPayment::factory()->create(['member_id' => $member->id]);
    $charge = MemberCharge::factory()->create(['member_id' => $member->id]);
    DB::table('member_payment_allocations')->insert(['payment_id' => $payment->id, 'charge_id' => $charge->id, 'amount' => 25_000]);
    refusedByPaymentsSchema(fn () => DB::table('member_payment_allocations')->insert(['payment_id' => $payment->id, 'charge_id' => $charge->id, 'amount' => 1]));
    refusedByPaymentsSchema(fn () => DB::table('member_payment_allocations')->insert(['payment_id' => $payment->id, 'charge_id' => MemberCharge::factory()->fees()->create(['member_id' => $member->id])->id, 'amount' => 0]));
});

it('numérote une seule fois par type et par centre, une attestation d\'origine par an, un jeton unique, une annulation motivée', function () {
    $document = GeneratedDocument::factory()->create(['number' => '2026-DCK-0001']);
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $document->organization_id, 'member_id' => $document->member_id,
        'type' => 'membership_contract', 'title' => 'Contrat', 'status' => 'valid', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
    DB::table('generated_documents')->insert($row(['type' => 'membership_certificate', 'fiscal_year' => 2026, 'number' => 'ATT-1']));
    DB::table('generated_documents')->insert($row(['type' => 'membership_certificate', 'fiscal_year' => 2026, 'number' => 'ATT-2', 'version' => 2]));
    DB::table('generated_documents')->insert($row(['type' => 'uploaded', 'title' => 'Bilan déposé']));

    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['number' => '2026-DCK-0001'])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['type' => 'membership_certificate', 'fiscal_year' => 2026, 'number' => 'ATT-3'])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['verification_token' => $document->verification_token])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['status' => 'cancelled'])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['version' => 0])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['type' => 'inconnu'])));
    expect($document->toArray())->not->toHaveKey('verification_token');
});

it('garde le numéro d\'origine pour la version suivante d\'un document, et ne catégorise que les documents déposés', function () {
    $document = GeneratedDocument::factory()->create(['number' => '2026-DCK-0002']);
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $document->organization_id, 'member_id' => $document->member_id,
        'type' => 'membership_contract', 'title' => 'Contrat', 'status' => 'valid', 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
    DB::table('generated_documents')->insert($row(['number' => '2026-DCK-0002', 'version' => 2, 'replaces_document_id' => $document->id]));
    DB::table('generated_documents')->insert($row(['type' => 'uploaded', 'title' => 'Bilan déposé', 'category' => 'financial_statements']));

    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['number' => '2026-DCK-0002', 'version' => 2])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['category' => 'financial_statements'])));
    refusedByPaymentsSchema(fn () => DB::table('generated_documents')->insert($row(['type' => 'uploaded', 'title' => 'Autre', 'category' => 'inconnue'])));
});

it('n\'active qu\'un modèle par type et refuse « document déposé » comme modèle', function () {
    $file = app(StorageService::class)->store('modèle', 'modele.docx', 'templates');
    $row = static fn (array $overrides = []): array => [
        'id' => strtolower((string) Str::ulid()), 'type' => 'receipt', 'version' => '1', 'file_id' => $file->id, 'variables' => '[]',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
    DB::table('document_templates')->insert($row());
    DB::table('document_templates')->insert($row(['version' => '2', 'is_active' => false]));

    refusedByPaymentsSchema(fn () => DB::table('document_templates')->insert($row(['version' => '3'])));
    refusedByPaymentsSchema(fn () => DB::table('document_templates')->insert($row(['version' => '2', 'is_active' => false])));
    refusedByPaymentsSchema(fn () => DB::table('document_templates')->insert($row(['type' => 'uploaded', 'version' => '1'])));
});

it('charge chaque modèle de la maquette en version 1 active, avec ses variables, une seule fois', function () {
    $this->seed(TemplateSeeder::class);
    $this->seed(TemplateSeeder::class);

    $templates = DocumentTemplate::query()->with('file')->get()->keyBy(fn (DocumentTemplate $template): string => $template->type->value);
    expect($templates->keys()->sort()->values()->all())->toBe(['credit_note', 'invoice', 'membership_certificate', 'membership_contract', 'quarterly_report_docx', 'quarterly_report_xlsx', 'radiation_notice', 'receipt']);

    $contract = $templates['membership_contract'];
    expect($contract->version)->toBe('1')
        ->and($contract->is_active)->toBeTrue()
        ->and($contract->variables)->toContain('member_ncc', 'cga_signature', 'qr_code')
        ->and($contract->file->path)->toStartWith('orgs/network/templates/')
        ->and(Storage::disk($contract->file->disk)->exists($contract->file->path))->toBeTrue()
        ->and($contract->file->sha256)->toBe(hash_file('sha256', (string) config('documents.templates.membership_contract.path')))
        ->and(DocumentType::from('receipt')->label())->toBe('Reçu')
        ->and(User::query()->count())->toBe(0);
});

it('relie le dossier à ses sommes dues, ses paiements, leur ventilation et ses documents', function () {
    $member = Member::factory()->active()->create();
    $charge = MemberCharge::factory()->create(['member_id' => $member->id, 'amount' => 25_000, 'amount_paid' => 10_000]);
    $proof = app(StorageService::class)->store('preuve', 'preuve.pdf', 'payments', $member->organization_id);
    $files = collect(['docx', 'pdf', 'xlsx'])->mapWithKeys(fn (string $ext): array => [$ext => app(StorageService::class)->store($ext, "document.{$ext}", 'documents', $member->organization_id)]);
    $this->seed(TemplateSeeder::class);
    $template = DocumentTemplate::query()->where('type', DocumentType::MembershipContract)->sole();
    $receipt = GeneratedDocument::factory()->create(['member_id' => $member->id, 'type' => DocumentType::Receipt, 'template_id' => $template->id,
        'docx_file_id' => $files['docx']->id, 'pdf_file_id' => $files['pdf']->id, 'xlsx_file_id' => $files['xlsx']->id]);
    $payment = MemberPayment::factory()->create(['member_id' => $member->id, 'amount' => 10_000, 'proof_file_id' => $proof->id, 'receipt_document_id' => $receipt->id]);
    $allocation = new PaymentAllocation;
    $allocation->payment_id = $payment->id;
    $allocation->charge_id = $charge->id;
    $allocation->amount = 10_000;
    $allocation->save();
    $verification = new DocumentVerification;
    $verification->document_id = $receipt->id;
    $verification->ip_prefix = '196.47.0.0/16';
    $verification->verified_at = now()->toImmutable();
    $verification->save();

    Tenancy::bypass(function () use ($member, $charge, $payment, $receipt, $allocation, $template, $files, $proof, $verification): void {
        expect($member->charges()->sole()->is($charge))->toBeTrue()
            ->and($member->payments()->sole()->is($payment))->toBeTrue()
            ->and($member->documents()->sole()->is($receipt))->toBeTrue()
            ->and($charge->balance())->toBe(15_000)
            ->and($charge->member->is($member))->toBeTrue()
            ->and($charge->allocations()->sole()->is($allocation))->toBeTrue()
            ->and($payment->member->is($member))->toBeTrue()
            ->and($payment->proof?->is($proof))->toBeTrue()
            ->and($payment->receipt?->is($receipt))->toBeTrue()
            ->and($payment->allocations()->sole()->amount)->toBe(10_000)
            ->and($allocation->payment->is($payment))->toBeTrue()
            ->and($allocation->charge->is($charge))->toBeTrue()
            ->and($receipt->member?->is($member))->toBeTrue()
            ->and($receipt->template?->is($template))->toBeTrue()
            ->and($receipt->pdf?->is($files['pdf']))->toBeTrue()
            ->and($receipt->docx?->is($files['docx']))->toBeTrue()
            ->and($receipt->xlsx?->is($files['xlsx']))->toBeTrue()
            ->and(DocumentVerification::query()->sole()->verified_at)->toBeInstanceOf(CarbonInterface::class)
            ->and($verification->ip_prefix)->toBe('196.47.0.0/16');
    }, 'Test des relations des paiements et des documents.');
});
