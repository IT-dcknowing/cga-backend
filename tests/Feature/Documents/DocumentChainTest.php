<?php

use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Composers\MembershipContractComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Jobs\GenerateDocument;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentNumbering;
use App\Domain\Documents\Support\VerificationCode;
use App\Domain\Documents\Templates\IncompleteTemplateDataException;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Files\PdfConverter;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Http\Exceptions\ExternalServiceException;
use Database\Seeders\TemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LibreOffice;
use Tests\Support\Spa;

/*
 * Chaîne documentaire (02-TRD §8.2, étape 3.4) : émission « en production » (numéro, code de
 * vérification, modèle actif), Job GenerateDocument en file « documents », modèle rempli avec le QR
 * code, conversion PDF, empreinte SHA-256, DOCX et PDF rangés par StorageService, liens signés,
 * DocumentReady, échec et relance. Le contrat d'adhésion est le premier document de la chaîne.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
});

function produceContract(Membership $agent, Member $member): TestResponse
{
    return inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/documents", ['type' => 'membership_contract']);
}

it('émet le contrat d\'un dossier validé « en production » : n° d\'adhésion, code de vérification, modèle actif, Job en file « documents »', function () {
    Queue::fake();
    $agent = documentAgent();
    $member = validatedMember($agent);

    $response = produceContract($agent, $member)->assertStatus(202)
        ->assertJsonPath('data.type', 'membership_contract')
        ->assertJsonPath('data.status', 'generating')
        ->assertJsonPath('data.number', $member->membership_number)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.member.id', $member->id)
        ->assertJsonPath('data.files.pdf', null)
        ->assertJsonPath('data.sha256', null);

    $document = storedDocument($response->json('data.id'));
    expect($document->verification_token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($document->template_id)->toBe(DocumentTemplate::query()->where('type', DocumentType::MembershipContract)->where('is_active', true)->value('id'))
        ->and($document->generated_by)->toBe($agent->user_id)
        ->and($document->issued_at)->not->toBeNull()
        ->and($response->json('data'))->not->toHaveKey('verification_token');
    Queue::assertPushedOn('documents', GenerateDocument::class, fn (GenerateDocument $job): bool => $job->documentId === $document->id && $job->membershipId === $agent->id);
});

it('produit le document : modèle rempli avec le QR code, PDF converti, empreinte SHA-256, DOCX et PDF rangés, DocumentReady', function () {
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    Event::fake([DocumentReady::class]);
    $agent = documentAgent();
    $member = validatedMember($agent, ['company_name' => 'ETS CHAÎNE DOCUMENTAIRE', 'phone' => '+2250707000099', 'sigle' => null]);

    $id = produceContract($agent, $member)->assertStatus(202)->json('data.id');

    $document = storedDocument($id);
    $pdf = (string) Storage::get($document->pdf?->path ?? '');
    $docx = storage_path('framework/testing/contrat-'.$id.'.docx');
    file_put_contents($docx, (string) Storage::get($document->docx?->path ?? ''));
    $body = templateParts($docx)['word/document.xml'];
    $zip = new ZipArchive;
    $zip->open($docx);
    $media = array_filter(array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn (string $name): bool => str_starts_with($name, 'word/media/'));
    $zip->close();
    unlink($docx);

    expect($document->status)->toBe(DocumentStatus::Valid)
        ->and($pdf)->toStartWith('%PDF')
        ->and($document->sha256)->toBe(hash('sha256', $pdf))
        ->and($document->pdf?->path)->toStartWith("orgs/{$agent->organization_id}/documents/".now()->format('Y').'/')->toEndWith('.pdf')
        ->and($document->pdf?->original_name)->toBe('contrat-dadhesion-'.Str::slug((string) $member->membership_number).'.pdf')
        ->and($document->docx?->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and($body)->not->toContain('${')
        ->and($body)->toContain('ETS CHAÎNE DOCUMENTAIRE')->toContain('+225 07 07 00 00 99')->toContain('Koffi Kouadio')
        // Signature, cachet et QR code de vérification (RG-DOC-02).
        ->and(count($media))->toBeGreaterThanOrEqual(3);
    Event::assertDispatched(DocumentReady::class, fn (DocumentReady $event): bool => $event->document->id === $id);
});

it('donne les liens signés du PDF et du Word une fois le document produit, et le lien télécharge le PDF', function () {
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $agent = documentAgent();
    $member = validatedMember($agent);
    $id = produceContract($agent, $member)->json('data.id');

    $response = inSpace($agent)->getJson("/api/v1/cga/documents/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.status_label', 'Valide')
        ->assertJsonPath('data.can.retry', false);
    expect($response->json('data.files.docx.url'))->toContain('/api/v1/files/')->toContain('signature=')
        ->and($response->json('data.files.pdf.name'))->toEndWith('.pdf');

    $this->get($response->json('data.files.pdf.url'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}/documents")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.sha256', storedDocument($id)->sha256);
});

it('RG-ORG-04 bloque la production tant que la fiche du CGA est incomplète, avec la liste des champs manquants', function () {
    Queue::fake();
    $agent = Membership::factory()->role(Role::CgaManager)->create();
    $member = validatedMember($agent);
    $draft = Member::factory()->create(['organization_id' => $agent->organization_id]);

    $response = produceContract($agent, $member)->assertStatus(409)->assertJsonPath('code', 'cga_profile_incomplete');
    // Contrôlée avant les règles du document : un brouillon reçoit la même réponse.
    produceContract($agent, $draft)->assertStatus(409)->assertJsonPath('code', 'cga_profile_incomplete');

    expect($response->json('details.missing'))->toContain('signature_file_id')->toContain('stamp_file_id');
    Queue::assertNothingPushed();
});

it('ne produit le contrat que pour un dossier validé du portefeuille CGA, et une seule fois', function () {
    Queue::fake();
    $agent = documentAgent();
    $refused = [
        Member::factory()->create(['organization_id' => $agent->organization_id]),
        Member::factory()->active()->cabinet()->create(['organization_id' => $agent->organization_id]),
        validatedMember($agent, ['status' => MemberStatus::Radiated]),
    ];
    foreach ($refused as $member) {
        produceContract($agent, $member)->assertStatus(409)->assertJsonPath('code', 'contract_unavailable');
    }

    $member = validatedMember($agent);
    produceContract($agent, $member)->assertStatus(202);
    produceContract($agent, $member)->assertStatus(409)->assertJsonPath('code', 'contract_already_issued');
    inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/documents", ['type' => 'invoice'])->assertUnprocessable()->assertJsonValidationErrors('type');
    inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/documents", [])->assertUnprocessable()->assertJsonValidationErrors('type');
    Queue::assertPushed(GenerateDocument::class, 1);
});

it('montre les documents avec le dossier, les produit avec documents.produce, et les garde dans leur centre', function () {
    Queue::fake();
    $agent = documentAgent();
    $member = validatedMember($agent);
    $id = produceContract($agent, $member)->json('data.id');
    $billing = documentAgent(Role::CgaBillingOfficer, $agent->organization_id);
    $owner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $agent->organization_id, 'member_id' => $member->id]);

    inSpace($billing)->getJson("/api/v1/cga/members/{$member->id}/documents")->assertOk()->assertJsonPath('data.0.id', $id);
    inSpace($billing)->getJson("/api/v1/cga/documents/{$id}")->assertOk();
    produceContract($billing, validatedMember($agent))->assertForbidden();
    inSpace(documentAgent(Role::CgaAccountant, $agent->organization_id))->getJson("/api/v1/cga/documents/{$id}")->assertOk();
    inSpace($owner)->getJson("/api/v1/cga/documents/{$id}")->assertForbidden();
    inSpace($owner)->getJson("/api/v1/cga/members/{$member->id}/documents")->assertForbidden();
    inSpace(documentAgent())->getJson("/api/v1/cga/documents/{$id}")->assertNotFound();
});

it('refuse les documents sans session', function () {
    $document = GeneratedDocument::factory()->create();

    $this->withHeaders(Spa::headers())->getJson("/api/v1/cga/documents/{$document->id}")->assertUnauthorized();
    $this->withHeaders(Spa::headers())->getJson("/api/v1/cga/members/{$document->member_id}/documents")->assertUnauthorized();
});

it('après le dernier essai en échec, le document passe « en échec » puis se relance avec son numéro et son code', function () {
    app()->instance(PdfConverter::class, new class implements PdfConverter
    {
        public function convert(string $sourcePath): string
        {
            throw new ExternalServiceException('libreoffice', 'Conversion impossible (test).');
        }
    });
    $agent = documentAgent();
    $member = validatedMember($agent);
    $id = produceContract($agent, $member)->assertStatus(202)->json('data.id');
    $token = storedDocument($id)->verification_token;
    $job = new GenerateDocument($id, $agent->id);

    expect($job->queue)->toBe('documents')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120])
        ->and(fn () => app()->call([$job, 'handle']))->toThrow(ExternalServiceException::class)
        ->and(storedDocument($id)->status)->toBe(DocumentStatus::Generating);

    $job->failed(new ExternalServiceException('libreoffice', 'Conversion impossible (test).'));
    expect(storedDocument($id)->status)->toBe(DocumentStatus::Failed);
    inSpace($agent)->getJson("/api/v1/cga/documents/{$id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.can.retry', true);
    produceContract($agent, $member)->assertStatus(409)->assertJsonPath('code', 'contract_already_issued');
    inSpace(documentAgent(Role::CgaBillingOfficer, $agent->organization_id))->postJson("/api/v1/cga/documents/{$id}/retry")->assertForbidden();

    Queue::fake();
    inSpace($agent)->postJson("/api/v1/cga/documents/{$id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'generating')
        ->assertJsonPath('data.number', $member->membership_number);
    expect(storedDocument($id)->verification_token)->toBe($token);
    Queue::assertPushedOn('documents', GenerateDocument::class, fn (GenerateDocument $retry): bool => $retry->documentId === $id);
    inSpace($agent)->postJson("/api/v1/cga/documents/{$id}/retry")->assertStatus(409)->assertJsonPath('code', 'document_not_failed');
});

it('fait échouer sans nouvel essai un modèle dont une variable reste sans valeur, ou un type sans composeur', function (Closure $breaks, string $exception) {
    $agent = documentAgent();
    $id = produceContract($agent, validatedMember($agent))->json('data.id');
    $breaks();

    $job = (new GenerateDocument($id, $agent->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertFailedWith($exception);
})->with([
    'variable sans valeur' => [fn () => app()->instance(MembershipContractComposer::class, new class implements DocumentComposer
    {
        public function compose(GeneratedDocument $document): DocumentContent
        {
            return new DocumentContent(['cga_name' => 'CGA incomplet']);
        }
    }), IncompleteTemplateDataException::class],
    'type sans composeur' => [fn () => config(['documents.templates.membership_contract.composer' => null]), LogicException::class],
]);

it('ne refait pas un document qui n\'est plus en production, et ignore un document disparu', function () {
    fakePdfConverter();
    $agent = documentAgent();
    $document = GeneratedDocument::factory()->create(['member_id' => validatedMember($agent)->id]);

    app()->call([new GenerateDocument($document->id, $agent->id), 'handle']);
    app()->call([new GenerateDocument('01HZZZZZZZZZZZZZZZZZZZZZZZ', $agent->id), 'handle']);

    expect(storedDocument($document->id)->pdf_file_id)->toBeNull()
        ->and(storedDocument($document->id)->status)->toBe(DocumentStatus::Valid);
});

it('numérote les documents par centre et par année selon leur format paramétré, sans trou', function () {
    $organization = Organization::factory()->create(['numbering_abbreviation' => 'NUM']);
    $numbering = app(DocumentNumbering::class);
    $next = fn (DocumentType $type, int $year): string => DB::transaction(fn (): string => $numbering->next($organization->id, $type, $year));

    expect([$next(DocumentType::Receipt, 2026), $next(DocumentType::Receipt, 2026), $next(DocumentType::MembershipCertificate, 2026), $next(DocumentType::RadiationNotice, 2027), $next(DocumentType::Receipt, 2027)])
        ->toBe(['REC-NUM-2026-00001', 'REC-NUM-2026-00002', 'ATT-NUM-2026-00001', 'NOT-NUM-2027-00001', 'REC-NUM-2027-00001']);

    app(SettingsService::class)->set('numbering.certificate.pattern', 'ATTEST/{YYYY}/{ABR}/{N:3}');
    expect($next(DocumentType::MembershipCertificate, 2026))->toBe('ATTEST/2026/NUM/002')
        ->and(fn () => $next(DocumentType::MembershipContract, 2026))->toThrow(LogicException::class);

    $unnumbered = Organization::factory()->create(['numbering_abbreviation' => null]);
    expect(fn () => DB::transaction(fn (): string => $numbering->next($unnumbered->id, DocumentType::Receipt)))
        ->toThrow(fn (BusinessRuleException $exception) => expect($exception->errorCode)->toBe('abbreviation_missing'));
});

it('donne à chaque document un code de vérification aléatoire en base64url et un QR code vers la page publique', function () {
    config(['documents.verification_url' => 'https://verification.cga-connect.ci/verifier']);
    $codes = app(VerificationCode::class);
    $code = $codes->generate();

    expect($code)->toMatch('/^[A-Za-z0-9_-]{43}$/')->not->toBe($codes->generate())
        ->and($codes->url($code))->toBe('https://verification.cga-connect.ci/verifier/'.$code)
        ->and($codes->qrCode($code))->toStartWith("\x89PNG");
});

it('produit un vrai contrat PDF avec LibreOffice', function () {
    LibreOffice::prepare();
    config(['queue.default' => 'sync']);
    $agent = documentAgent();

    $document = storedDocument(produceContract($agent, validatedMember($agent))->assertStatus(202)->json('data.id'));
    $pdf = (string) Storage::get($document->pdf?->path ?? '');

    expect($document->status)->toBe(DocumentStatus::Valid)
        ->and($pdf)->toStartWith('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(10_000);
})->group('libreoffice');

it('produit un document en moins de 10 s en local, profil LibreOffice prêt (critère de l\'étape 3.4)', function () {
    LibreOffice::prepare();
    $agent = documentAgent();
    $warmUp = produceContract($agent, validatedMember($agent))->json('data.id');
    $timed = produceContract($agent, validatedMember($agent))->json('data.id');
    app()->call([new GenerateDocument($warmUp, $agent->id), 'handle']);

    $started = hrtime(true);
    app()->call([new GenerateDocument($timed, $agent->id), 'handle']);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect(storedDocument($timed)->status)->toBe(DocumentStatus::Valid)
        ->and($seconds)->toBeLessThan(10.0);
})->group('performance');
