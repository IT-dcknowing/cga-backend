<?php

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use Database\Seeders\TemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Documents (06-IMPLEMENTATION-PLAN étape 3.5) : attestation (RG-DOC-06, 07), reçu d'un paiement
 * confirmé, courrier de radiation au CDI (effet 3 de F-ADH-06), régénération et duplicata (RG-ADH-07),
 * annulation (F-DOC-03, RG-DOC-08), dépôt par le CGA (F-DOC-05), documents de l'adhérent en PDF
 * seulement (RG-DOC-04). Chaque modèle se remplit sans laisser de variable ${…}.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
});

/**
 * @param  array<string, mixed>  $payload
 */
function produceDocument(Membership $agent, Member $member, array $payload): TestResponse
{
    return inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()])->postJson("/api/v1/cga/members/{$member->id}/documents", $payload);
}

function lastDocument(Member $member, DocumentType $type): GeneratedDocument
{
    return GeneratedDocument::query()->withoutGlobalScopes()->with(['pdf', 'docx'])->where('member_id', $member->id)->where('type', $type)->latest('version')->latest('created_at')->firstOrFail();
}

describe('attestation d\'adhésion (F-DOC-02)', function () {
    it('RG-DOC-06 produit l\'attestation de l\'année : n° ATT, exercice, validité de 12 mois, logo du centre', function () {
        $agent = documentAgent();
        $member = validatedMember($agent, ['company_name' => 'ETS ATTESTATION']);
        $abbreviation = $agent->organization->numbering_abbreviation;

        produceDocument($agent, $member, ['type' => 'membership_certificate'])->assertStatus(202)
            ->assertJsonPath('data.number', "ATT-{$abbreviation}-".now()->year.'-00001')
            ->assertJsonPath('data.fiscal_year', now()->year)
            ->assertJsonPath('data.valid_until', today()->addMonthsNoOverflow(12)->toDateString());

        $certificate = lastDocument($member, DocumentType::MembershipCertificate);
        expect($certificate->status)->toBe(DocumentStatus::Valid)
            ->and(documentBody($certificate))->not->toContain('${')->toContain('ETS ATTESTATION')->toContain((string) $member->membership_number);
    });

    it('RG-DOC-06 délivre une attestation d\'origine par an ; le duplicata garde le numéro et la validité', function () {
        $agent = documentAgent();
        $member = validatedMember($agent);
        produceDocument($agent, $member, ['type' => 'membership_certificate'])->assertStatus(202);
        $original = lastDocument($member, DocumentType::MembershipCertificate);

        produceDocument($agent, $member, ['type' => 'membership_certificate'])->assertStatus(409)->assertJsonPath('code', 'certificate_already_issued');
        inSpace($agent)->postJson("/api/v1/cga/documents/{$original->id}/regenerate")->assertStatus(202)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.number', $original->number)
            ->assertJsonPath('data.replaces_document_id', $original->id)
            ->assertJsonPath('data.valid_until', $original->valid_until?->toDateString());
        inSpace($agent)->postJson("/api/v1/cga/documents/{$original->id}/regenerate")->assertStatus(409)->assertJsonPath('code', 'document_superseded');

        expect(storedDocument($original->id)->status)->toBe(DocumentStatus::Valid)
            ->and(lastDocument($member, DocumentType::MembershipCertificate)->status)->toBe(DocumentStatus::Valid);
    });

    it('RG-DOC-07 la refuse à un dossier radié, en radiation, du portefeuille Cabinet, ou dont l\'attestation est bloquée', function () {
        $agent = documentAgent();

        foreach ([MemberStatus::Radiated, MemberStatus::RadiationPending] as $status) {
            produceDocument($agent, validatedMember($agent, ['status' => $status]), ['type' => 'membership_certificate'])->assertStatus(409)->assertJsonPath('code', 'certificate_unavailable');
        }
        $cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $agent->organization_id]);
        produceDocument($agent, $cabinet, ['type' => 'membership_certificate'])->assertStatus(409)->assertJsonPath('code', 'certificate_unavailable');
        produceDocument($agent, validatedMember($agent, ['attestation_blocked' => true]), ['type' => 'membership_certificate'])->assertStatus(409)->assertJsonPath('code', 'attestation_blocked');
    });
});

describe('reçu (F-PAY-02, RG-PAY-02)', function () {
    it('produit le reçu numéroté à la confirmation d\'un paiement : montant en lettres, une ligne par somme réglée', function () {
        $agent = documentAgent();
        $member = validatedMember($agent, ['company_name' => 'ETS REÇU']);
        MemberCharge::factory()->create(['member_id' => $member->id, 'amount' => 25_000]);
        MemberCharge::factory()->fees()->create(['member_id' => $member->id]);

        $paymentId = inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/payments", ['amount' => 40_000, 'paid_on' => today()->toDateString(), 'method' => 'mobile_money', 'reference' => 'OM-123'])
            ->assertCreated()->json('data.id');

        $payment = MemberPayment::query()->withoutGlobalScopes()->findOrFail($paymentId);
        $receipt = lastDocument($member, DocumentType::Receipt);
        expect($receipt->number)->toBe($payment->receipt_number)
            ->and($payment->receipt_document_id)->toBe($receipt->id)
            ->and($receipt->status)->toBe(DocumentStatus::Valid)
            ->and($receipt->visible_to_member)->toBeTrue()
            ->and(documentBody($receipt))->not->toContain('${')
            ->toContain('quarante mille francs CFA')->toContain('Droit d&#039;adhésion')->toContain('Honoraires du ')->toContain('OM-123');

        produceDocument($agent, $member, ['type' => 'receipt', 'payment_id' => $paymentId])->assertStatus(409)->assertJsonPath('code', 'receipt_already_issued');
    });

    it('ne remet pas la confirmation en cause quand la fiche du centre est incomplète : le reçu se produit ensuite à la demande', function () {
        $incomplete = Membership::factory()->role(Role::CgaManager)->create();
        $member = validatedMember($incomplete);
        MemberCharge::factory()->create(['member_id' => $member->id]);

        inSpace($incomplete)->postJson("/api/v1/cga/members/{$member->id}/payments", ['amount' => 25_000, 'paid_on' => today()->toDateString(), 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.status', 'confirmed');
        expect(GeneratedDocument::query()->withoutGlobalScopes()->where('member_id', $member->id)->exists())->toBeFalse()
            ->and(ActivityLog::query()->where('action', 'documents.receipt_deferred')->where('error_code', 'cga_profile_incomplete')->where('result', 'business_error')->exists())->toBeTrue();

        $agent = documentAgent();
        $confirmed = MemberPayment::factory()->create(['member_id' => validatedMember($agent)->id, 'receipt_number' => 'REC-TST-2026-00042']);
        $pending = MemberPayment::factory()->create(['member_id' => $confirmed->member_id, 'status' => PaymentStatus::PendingConfirmation]);
        $owner = Member::query()->withoutGlobalScopes()->findOrFail($confirmed->member_id);

        produceDocument($agent, $owner, ['type' => 'receipt', 'payment_id' => $confirmed->id])->assertStatus(202)->assertJsonPath('data.number', 'REC-TST-2026-00042');
        produceDocument($agent, $owner, ['type' => 'receipt', 'payment_id' => $pending->id])->assertStatus(409)->assertJsonPath('code', 'receipt_unavailable');
        produceDocument($agent, $owner, ['type' => 'receipt', 'payment_id' => MemberPayment::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('payment_id');
        produceDocument($agent, $owner, ['type' => 'receipt'])->assertUnprocessable()->assertJsonValidationErrors('payment_id');
        produceDocument($agent, $owner, ['type' => 'membership_contract', 'payment_id' => $confirmed->id])->assertUnprocessable()->assertJsonValidationErrors('payment_id');
    });
});

describe('radiation (F-ADH-06, effet 3 ; RG-DOC-08)', function () {
    it('produit le courrier au CDI à la validation, réservé au centre, et annule l\'attestation en cours de validité', function () {
        $agent = documentAgent();
        $member = validatedMember($agent, ['company_name' => 'ETS RADIÉE']);
        produceDocument($agent, $member, ['type' => 'membership_certificate'])->assertStatus(202);
        $certificate = lastDocument($member, DocumentType::MembershipCertificate);

        $space = inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
        $space->post("/api/v1/cga/members/{$member->id}/radiation", [
            'initiator' => 'cga', 'reason' => 'reclassified_real_regime', 'effective_date' => today()->subDays(5)->toDateString(),
            'observation' => 'Reclassement au RSI.', 'sp_request_reference' => 'DEM-2026-09',
        ], ['Accept' => 'application/json'])->assertCreated();
        $space->postJson("/api/v1/cga/members/{$member->id}/radiation/validate", ['outcome' => 'closed', 'validation_reference' => 'SP-2026-201'])->assertOk();

        $cancelled = storedDocument($certificate->id);
        $notice = lastDocument($member, DocumentType::RadiationNotice);
        expect($cancelled->status)->toBe(DocumentStatus::Cancelled)
            ->and($cancelled->cancel_reason)->toBe('Radiation validée le '.today()->format('d/m/Y').'.')
            ->and($cancelled->cancelled_by)->toBe($agent->user_id)
            ->and($notice->number)->toBe('NOT-'.$agent->organization->numbering_abbreviation.'-'.now()->year.'-00001')
            ->and($notice->visible_to_member)->toBeFalse()
            ->and($notice->status)->toBe(DocumentStatus::Valid)
            ->and(documentBody($notice))->not->toContain('${')->toContain('ETS RADIÉE')->toContain('Reclassement au régime réel')->toContain('SP-2026-201');

        produceDocument($agent, Member::query()->withoutGlobalScopes()->findOrFail($member->id), ['type' => 'radiation_notice'])->assertStatus(409)->assertJsonPath('code', 'radiation_notice_already_issued');
        produceDocument($agent, validatedMember($agent), ['type' => 'radiation_notice'])->assertStatus(409)->assertJsonPath('code', 'radiation_notice_unavailable');
    });
});

describe('régénération et annulation (RG-ADH-07, F-DOC-03)', function () {
    it('RG-ADH-07 régénère le contrat après un changement de la fiche : nouvelle version, l\'ancienne reste valide', function () {
        $agent = documentAgent();
        $member = validatedMember($agent, ['company_name' => 'ANCIEN NOM']);
        produceDocument($agent, $member, ['type' => 'membership_contract'])->assertStatus(202);
        $first = lastDocument($member, DocumentType::MembershipContract);
        Member::query()->withoutGlobalScopes()->whereKey($member->id)->update(['company_name' => 'NOUVEAU NOM']);

        inSpace($agent)->postJson("/api/v1/cga/documents/{$first->id}/regenerate")->assertStatus(202)->assertJsonPath('data.version', 2);

        $second = lastDocument($member, DocumentType::MembershipContract);
        expect($second->number)->toBe($first->number)
            ->and(documentBody($second))->toContain('NOUVEAU NOM')->not->toContain('ANCIEN NOM')
            ->and(storedDocument($first->id)->status)->toBe(DocumentStatus::Valid);
        inSpace($agent)->getJson("/api/v1/cga/documents/{$second->id}")->assertJsonPath('data.can.regenerate', true)->assertJsonPath('data.can.cancel', true);
        inSpace(documentAgent(Role::CgaBillingOfficer, $agent->organization_id))->postJson("/api/v1/cga/documents/{$second->id}/regenerate")->assertForbidden();
    });

    it('ne régénère que le contrat et l\'attestation, valides, pour un dossier qui y a toujours droit', function () {
        $agent = documentAgent();
        $member = validatedMember($agent);
        $receipt = GeneratedDocument::factory()->create(['member_id' => $member->id, 'type' => DocumentType::Receipt, 'number' => 'REC-X']);
        $cancelled = GeneratedDocument::factory()->create(['member_id' => $member->id, 'status' => DocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Erreur']);
        $radiatedContract = GeneratedDocument::factory()->create(['member_id' => validatedMember($agent, ['status' => MemberStatus::Radiated])->id, 'number' => 'C-1']);

        inSpace($agent)->postJson("/api/v1/cga/documents/{$receipt->id}/regenerate")->assertStatus(409)->assertJsonPath('code', 'document_not_regenerable');
        inSpace($agent)->postJson("/api/v1/cga/documents/{$cancelled->id}/regenerate")->assertStatus(409)->assertJsonPath('code', 'document_not_valid');
        inSpace($agent)->postJson("/api/v1/cga/documents/{$radiatedContract->id}/regenerate")->assertStatus(409)->assertJsonPath('code', 'contract_unavailable');
    });

    it('F-DOC-03 annule un document avec son motif : gérant ou expert-comptable, document conservé ; un nouveau contrat prend la version suivante', function () {
        $agent = documentAgent();
        $member = validatedMember($agent);
        produceDocument($agent, $member, ['type' => 'membership_contract'])->assertStatus(202);
        $contract = lastDocument($member, DocumentType::MembershipContract);

        inSpace(documentAgent(Role::CgaAccountant, $agent->organization_id))->postJson("/api/v1/cga/documents/{$contract->id}/cancel", ['reason' => 'Erreur'])->assertForbidden();
        inSpace($agent)->postJson("/api/v1/cga/documents/{$contract->id}/cancel", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        inSpace($agent)->postJson("/api/v1/cga/documents/{$contract->id}/cancel", ['reason' => 'Adresse erronée'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Adresse erronée')
            ->assertJsonPath('data.can.cancel', false);
        inSpace($agent)->postJson("/api/v1/cga/documents/{$contract->id}/cancel", ['reason' => 'Encore'])->assertStatus(409)->assertJsonPath('code', 'document_not_valid');

        produceDocument($agent, $member, ['type' => 'membership_contract'])->assertStatus(202)->assertJsonPath('data.number', $contract->number)->assertJsonPath('data.version', 2);
        expect(storedDocument($contract->id)->cancelled_by)->toBe($agent->user_id);
    });
});

describe('dépôt et espace de l\'adhérent (F-DOC-05, RG-DOC-04)', function () {
    it('F-DOC-05 dépose un PDF dans l\'espace de l\'adhérent, qui en est prévenu', function () {
        Event::fake([DocumentReady::class]);
        $agent = documentAgent(Role::CgaSecretary);
        $member = validatedMember($agent);
        $pdf = "%PDF-1.4\n% états financiers visés\n%%EOF";
        $upload = fn (array $payload) => inSpace($agent)->post("/api/v1/cga/members/{$member->id}/documents/upload", $payload, ['Accept' => 'application/json']);

        $response = $upload(['title' => 'États financiers 2025', 'category' => 'financial_statements', 'file' => UploadedFile::fake()->createWithContent('etats.pdf', $pdf)])
            ->assertCreated()
            ->assertJsonPath('data.type', 'uploaded')
            ->assertJsonPath('data.category_label', 'États financiers visés')
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.sha256', hash('sha256', $pdf))
            ->assertJsonPath('data.files.docx', null);

        expect($response->json('data.files.pdf.url'))->toContain('signature=');
        Event::assertDispatched(DocumentReady::class, fn (DocumentReady $event): bool => $event->document->id === $response->json('data.id'));
        $upload(['title' => 'Image', 'category' => 'other', 'file' => UploadedFile::fake()->image('photo.png')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $upload(['title' => '', 'category' => 'inconnue'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'category', 'file']);
        inSpace(documentAgent(Role::CgaTrainer, $agent->organization_id))->post("/api/v1/cga/members/{$member->id}/documents/upload", ['title' => 'X', 'category' => 'other'], ['Accept' => 'application/json'])->assertForbidden();
    });

    it('RG-DOC-04 montre à l\'adhérent ses documents en PDF seulement, annulés compris, jamais le courrier au CDI', function () {
        $agent = documentAgent();
        $member = validatedMember($agent);
        $owner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $agent->organization_id, 'member_id' => $member->id]);
        $pdf = app(StorageService::class)->store("%PDF-1.4\n%%EOF", 'contrat.pdf', 'documents', $agent->organization_id);
        $contract = GeneratedDocument::factory()->create(['member_id' => $member->id, 'pdf_file_id' => $pdf->id, 'docx_file_id' => $pdf->id]);
        $cancelled = GeneratedDocument::factory()->create(['member_id' => $member->id, 'type' => DocumentType::MembershipCertificate, 'number' => 'ATT-1', 'status' => DocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Radiation']);
        GeneratedDocument::factory()->create(['member_id' => $member->id, 'type' => DocumentType::RadiationNotice, 'number' => 'NOT-1', 'visible_to_member' => false]);
        GeneratedDocument::factory()->create(['member_id' => $member->id, 'type' => DocumentType::Receipt, 'number' => 'REC-1', 'status' => DocumentStatus::Generating]);
        GeneratedDocument::factory()->create(['member_id' => validatedMember($agent)->id]);

        $response = inSpace($owner)->getJson('/api/v1/member/documents')->assertOk()->assertJsonCount(2, 'data');

        expect(collect($response->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$contract->id, $cancelled->id])
            ->and($response->json('data.0'))->not->toHaveKey('files')->not->toHaveKey('sha256')
            ->and(collect($response->json('data'))->firstWhere('id', $contract->id)['pdf']['url'])->toContain('signature=');
        inSpace($agent)->getJson('/api/v1/member/documents')->assertForbidden();
    });
});
