<?php

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Payments\Actions\DeliverCertifiedInvoice;
use App\Domain\Payments\Enums\CertificationMode;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Events\InvoiceCertified;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\InvoicingProvider;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\TemplateSeeder;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
 * Factures certifiées FNE en mode manuel (06-IMPLEMENTATION-PLAN étape 3.13, F-PAY-05, F-PAY-06,
 * RG-FNE-01, 03) : émission des sommes dues choisies avec un n° FAC, des montants hors taxe, de TVA et
 * toutes taxes comprises calculés par le serveur ; aucune remise avant certification ; certification
 * saisie (n° FNE et PDF certifié exigés) ; facture certifiée immuable, corrigée par un avoir total ;
 * annulation d'une facture jamais certifiée.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
});

describe('émission (F-PAY-05)', function () {
    it('émet la facture des sommes dues choisies : n° FAC, une ligne par somme due, hors taxe, TVA et TTC calculés par le serveur', function () {
        $agent = documentAgent(Role::CgaBillingOfficer);
        $member = validatedMember($agent, ['company_name' => 'ETS FACTURÉE']);
        $entryFee = taxedCharge($member, 25_000);
        $fees = taxedCharge($member, 15_000, ChargeKind::Fees);
        $abbreviation = $agent->organization->numbering_abbreviation;

        issueInvoice($agent, $member, [$fees->id, $entryFee->id], ['amount_incl_tax' => 1, 'status' => 'certified', 'number' => 'FAC-PIRATE', 'fne_number' => 'X'])
            ->assertCreated()
            ->assertJsonPath('data.number', "FAC-{$abbreviation}-".now()->year.'-00001')
            ->assertJsonPath('data.type', 'invoice')
            ->assertJsonPath('data.status', 'pending_certification')
            ->assertJsonPath('data.status_label', 'En attente de certification')
            ->assertJsonPath('data.certification_mode', 'manual')
            ->assertJsonPath('data.fne_number', null)
            ->assertJsonPath('data.member.company_name', 'ETS FACTURÉE')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.label', 'Honoraires du 01/10/2026 au 31/10/2026')
            ->assertJsonPath('data.lines.0.unit_amount', 15_000)
            ->assertJsonPath('data.lines.1.label', 'Droit d\'adhésion')
            ->assertJsonPath('data.lines.1.unit_amount', 25_000)
            ->assertJsonPath('data.lines.1.tax_rate', 18)
            ->assertJsonPath('data.amount_excl_tax', 40_000)
            ->assertJsonPath('data.tax_amount', 7_200)
            ->assertJsonPath('data.amount_incl_tax', 47_200)
            ->assertJsonPath('data.pdf', null)
            ->assertJsonPath('data.can.certify_manually', true);

        expect($entryFee->refresh()->invoice_id)->not->toBeNull()->toBe($fees->refresh()->invoice_id);
        issueInvoice($agent, $member, [$entryFee->id])->assertUnprocessable()->assertJsonValidationErrors('charge_ids');
        issueInvoice($agent, $member, [taxedCharge($member, 1_000, ChargeKind::Fees, ['status' => 'cancelled', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])->id])->assertUnprocessable()->assertJsonValidationErrors('charge_ids');
        issueInvoice($agent, $member, [taxedCharge(validatedMember($agent), 1_000)->id])->assertUnprocessable()->assertJsonValidationErrors('charge_ids');
        issueInvoice($agent, $member, [])->assertUnprocessable()->assertJsonValidationErrors('charge_ids');
        issueInvoice($agent, validatedMember(documentAgent()), [$fees->id])->assertUnprocessable()->assertJsonValidationErrors('member_id');

        invoiceSpace($agent)->getJson('/api/v1/cga/invoices?filter[status]=pending_certification')->assertOk()->assertJsonCount(1, 'data');
        invoiceSpace($agent)->getJson("/api/v1/cga/invoices?filter[member_id]={$member->id}&sort=-number")->assertOk()->assertJsonPath('data.0.amount_incl_tax', 47_200);
        invoiceSpace($agent)->getJson('/api/v1/cga/invoices?sort=fne_number')->assertUnprocessable()->assertJsonValidationErrors('sort');
    });

    it('réserve les factures à ceux qui les émettent, exige une fiche du centre complète et un mode de certification disponible', function () {
        $manager = documentAgent();
        $member = validatedMember($manager);
        $charge = taxedCharge($member, 25_000);

        foreach ([Role::CgaSecretary, Role::CgaTrainer] as $role) {
            $agent = documentAgent($role, $manager->organization_id);
            issueInvoice($agent, $member, [$charge->id])->assertForbidden();
            invoiceSpace($agent)->getJson('/api/v1/cga/invoices')->assertForbidden();
        }
        foreach ([Role::CgaAccountantExpert, Role::CgaAccountant] as $role) {
            invoiceSpace(documentAgent($role, $manager->organization_id))->getJson('/api/v1/cga/invoices')->assertOk();
        }

        $incomplete = Membership::factory()->role(Role::CgaManager)->create();
        $other = validatedMember($incomplete);
        issueInvoice($incomplete, $other, [taxedCharge($other, 25_000)->id])->assertStatus(409)->assertJsonPath('code', 'cga_profile_incomplete');

        $provider = new InvoicingProvider;
        $provider->organization_id = $manager->organization_id;
        $provider->mode = CertificationMode::SellFlow;
        $provider->active = true;
        Tenancy::bypass(fn () => $provider->save(), 'Test : mode Sell Flow sans pilote.');
        issueInvoice($manager, $member, [$charge->id])->assertStatus(409)->assertJsonPath('code', 'certification_mode_unavailable');
        expect($charge->refresh()->invoice_id)->toBeNull();
    });
});

describe('certification saisie en mode manuel (RG-FNE-01, 03)', function () {
    it('RG-FNE-01 ne remet aucune facture avant sa certification ; le mode manuel exige le n° FNE et le PDF certifié', function () {
        Event::fake([InvoiceCertified::class]);
        $agent = documentAgent();
        $member = validatedMember($agent);
        $owner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $agent->organization_id, 'member_id' => $member->id]);
        $id = issueInvoice($agent, $member, [taxedCharge($member, 25_000)->id])->assertCreated()->json('data.id');

        invoiceSpace($owner)->getJson('/api/v1/member/documents')->assertOk()->assertJsonCount(0, 'data');
        expect(GeneratedDocument::query()->withoutGlobalScopes()->where('member_id', $member->id)->exists())->toBeFalse();

        certifyManually($agent, $id, ['fne_number' => 'FNE-2026-000123'])->assertUnprocessable()->assertJsonValidationErrors('file');
        certifyManually($agent, $id, ['file' => certifiedPdf()])->assertUnprocessable()->assertJsonValidationErrors('fne_number');
        certifyManually($agent, $id, ['fne_number' => 'FNE-2026-000123', 'file' => UploadedFile::fake()->image('facture.png')])->assertUnprocessable()->assertJsonValidationErrors('file');
        certifyManually(documentAgent(Role::CgaSecretary, $agent->organization_id), $id, ['fne_number' => 'FNE-2026-000123', 'file' => certifiedPdf()])->assertForbidden();

        $provider = new InvoicingProvider;
        $provider->organization_id = $agent->organization_id;
        $provider->mode = CertificationMode::Manual;
        $provider->active = true;
        $provider->fne_number_pattern = '^FNE-\d{4}-\d{6}$';
        Tenancy::bypass(fn () => $provider->save(), 'Test : contrôle du n° FNE.');
        certifyManually($agent, $id, ['fne_number' => 'ABC', 'file' => certifiedPdf()])->assertUnprocessable()->assertJsonValidationErrors('fne_number');

        certifyManually($agent, $id, ['fne_number' => 'FNE-2026-000123', 'file' => certifiedPdf()])->assertOk()
            ->assertJsonPath('data.status', 'certified')
            ->assertJsonPath('data.fne_number', 'FNE-2026-000123')
            ->assertJsonPath('data.can.certify_manually', false)
            ->assertJsonPath('data.can.issue_credit_note', true)
            ->assertJsonPath('data.can.cancel', false);
        expect(invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->json('data.pdf.url'))->toContain('signature=');
        Event::assertDispatched(InvoiceCertified::class);

        $documents = invoiceSpace($owner)->getJson('/api/v1/member/documents')->assertOk()->assertJsonCount(1, 'data');
        expect($documents->json('data.0.type'))->toBe(DocumentType::Invoice->value)
            ->and($documents->json('data.0.pdf.url'))->toContain('signature=');
        certifyManually($agent, $id, ['fne_number' => 'FNE-2026-000124', 'file' => certifiedPdf()])->assertStatus(409)->assertJsonPath('code', 'invoice_not_awaiting_manual_certification');
    });

    it('RG-FNE-01 une facture certifiée est immuable : elle se corrige par un avoir total, lui-même certifié, qui libère ses sommes dues', function () {
        $agent = documentAgent();
        $member = validatedMember($agent);
        $charge = taxedCharge($member, 25_000);
        $id = issueInvoice($agent, $member, [$charge->id])->json('data.id');
        certifyManually($agent, $id, ['fne_number' => 'FNE-1', 'file' => certifiedPdf()])->assertOk();

        $invoice = Tenancy::bypass(fn () => Invoice::query()->findOrFail($id), 'Test : facture certifiée.');
        $invoice->amount_incl_tax = 1;
        expect(fn () => Tenancy::bypass(fn () => $invoice->save(), 'Test : modification interdite.'))->toThrow(LogicException::class);

        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/cancel", ['reason' => 'Erreur'])->assertStatus(409)->assertJsonPath('code', 'invoice_not_cancellable');
        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/resubmit")->assertStatus(409)->assertJsonPath('code', 'invoice_not_resubmittable');
        issueInvoice($agent, $member, [$charge->id])->assertUnprocessable();

        $creditNoteId = invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/credit-note")->assertCreated()
            ->assertJsonPath('data.type', 'credit_note')
            ->assertJsonPath('data.original.id', $id)
            ->assertJsonPath('data.number', 'FAC-'.$agent->organization->numbering_abbreviation.'-'.now()->year.'-00002')
            ->assertJsonPath('data.amount_incl_tax', 29_500)
            ->assertJsonPath('data.status', 'pending_certification')
            ->json('data.id');
        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/credit-note")->assertStatus(409)->assertJsonPath('code', 'credit_note_exists');
        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$creditNoteId}/credit-note")->assertStatus(409)->assertJsonPath('code', 'credit_note_unavailable');
        expect($charge->refresh()->invoice_id)->toBe($id);

        certifyManually($agent, $creditNoteId, ['fne_number' => 'FNE-AV-1', 'file' => certifiedPdf()])->assertOk()->assertJsonPath('data.status', 'certified');

        expect($charge->refresh()->invoice_id)->toBeNull();
        invoiceSpace($agent)->getJson("/api/v1/cga/invoices/{$id}")->assertJsonPath('data.status', 'certified')
            ->assertJsonPath('data.credit_note.id', $creditNoteId)
            ->assertJsonPath('data.amount_incl_tax', 29_500)
            ->assertJsonPath('data.can.issue_credit_note', false);
        issueInvoice($agent, $member, [$charge->id])->assertCreated();
    });

    it('annule avec son motif une facture jamais certifiée : son numéro reste pris, ses sommes dues redeviennent facturables', function () {
        $agent = documentAgent();
        $member = validatedMember($agent);
        $charge = taxedCharge($member, 25_000);
        $id = issueInvoice($agent, $member, [$charge->id])->json('data.id');

        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/cancel", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        invoiceSpace(documentAgent(Role::CgaSecretary, $agent->organization_id))->postJson("/api/v1/cga/invoices/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/cancel", ['reason' => 'Mauvais client'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Mauvais client');
        invoiceSpace($agent)->postJson("/api/v1/cga/invoices/{$id}/cancel", ['reason' => 'Encore'])->assertStatus(409);

        expect($charge->refresh()->invoice_id)->toBeNull();
        issueInvoice($agent, $member, [$charge->id])->assertCreated()->assertJsonPath('data.number', 'FAC-'.$agent->organization->numbering_abbreviation.'-'.now()->year.'-00002');
        invoiceSpace(documentAgent())->getJson("/api/v1/cga/invoices/{$id}")->assertNotFound();
    });
});

it('produit le PDF d\'une facture certifiée avec ses éléments de certification, sans variable restante', function () {
    $agent = documentAgent();
    $member = validatedMember($agent, ['company_name' => 'ETS PDF FACTURE']);
    $invoice = new Invoice;
    $invoice->organization_id = $agent->organization_id;
    $invoice->member_id = $member->id;
    $invoice->type = InvoiceType::Invoice;
    $invoice->number = 'FAC-TST-2026-00042';
    $invoice->issue_date = today()->toImmutable();
    $invoice->lines = [['charge_id' => null, 'label' => 'Honoraires du 01/10/2026 au 31/10/2026', 'quantity' => 1, 'unit_amount' => 15_000, 'tax_rate' => 18.0, 'amount' => 15_000]];
    $invoice->amount_excl_tax = 15_000;
    $invoice->tax_amount = 2_700;
    $invoice->amount_incl_tax = 17_700;
    $invoice->status = InvoiceStatus::Certified;
    $invoice->certification_mode = CertificationMode::DgiApi;
    $invoice->fne_number = 'FNE-PDF-0042';
    $invoice->fne_qr_payload = 'https://fne.example/verifier/0042';
    $invoice->fne_stamp = 'CACHET-0042';
    $invoice->certified_at = now()->toImmutable();
    $invoice->created_by = $agent->user_id;
    Tenancy::bypass(fn () => $invoice->save(), 'Test : facture certifiée.');

    app(Container::class)->call(fn (TenantContext $context, DeliverCertifiedInvoice $deliver) => $context->runAs($agent, fn () => $deliver->execute($invoice, $agent)));

    $document = GeneratedDocument::query()->withoutGlobalScopes()->with(['pdf', 'docx'])->where('source_id', $invoice->id)->sole();
    expect($document->type)->toBe(DocumentType::Invoice)
        ->and($document->number)->toBe('FAC-TST-2026-00042')
        ->and(documentBody($document))->not->toContain('${')
        ->toContain('ETS PDF FACTURE')->toContain('FNE-PDF-0042')->toContain('CACHET-0042')
        ->toContain('17 700 FCFA')->toContain('2 700 FCFA')->toContain('18 %')
        ->toContain('dix-sept mille sept cents francs CFA');
});
