<?php

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Database\Seeders\TemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Versions des modèles de documents (06-IMPLEMENTATION-PLAN étape 3.12, F-ADM-02, RG-DATA-03) :
 * l'administrateur de la plateforme dépose une version, la plateforme en contrôle les variables, la
 * prévisualise avec des données de test, puis l'active après validation du CNCGA ; elle s'applique aux
 * productions suivantes, chaque document déjà émis garde sa version.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
});

function templatesAdmin(): Membership
{
    return Membership::factory()->role(Role::PlatformAdmin)->create();
}

/**
 * Copie d'un modèle Word dont le corps est transformé.
 *
 * @param  callable(string): string  $change
 */
function changedTemplate(string $type, callable $change): UploadedFile
{
    $path = storage_path('framework/testing/'.$type.'-'.uniqid().'.docx');
    copy((string) config("documents.templates.{$type}.path"), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString('word/document.xml', $change((string) $zip->getFromName('word/document.xml')));
    $zip->close();

    return UploadedFile::fake()->createWithContent($type.'.docx', (string) file_get_contents($path));
}

/**
 * Fichier déposé dont le type est relevé sur son contenu réel, comme en production (un faux fichier de
 * test le déduirait de l'extension).
 */
function realUpload(string $contents, string $name): UploadedFile
{
    $path = storage_path('framework/testing/'.uniqid().'-'.$name);
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Archive Word sans corps de document : reconnue comme DOCX, illisible comme modèle.
 */
function withoutBody(string $type): UploadedFile
{
    $path = storage_path('framework/testing/'.$type.'-'.uniqid().'.docx');
    copy((string) config("documents.templates.{$type}.path"), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->deleteName('word/document.xml');
    $zip->close();

    return new UploadedFile($path, $type.'.docx', null, null, true);
}

/**
 * Modèle « réel » du contrat : le modèle provisoire avec une mention en plus.
 */
function officialContract(): UploadedFile
{
    return changedTemplate('membership_contract', static fn (string $xml): string => preg_replace(
        '#(<w:body>)#',
        '$1<w:p><w:r><w:t>MODELE OFFICIEL DU RESEAU</w:t></w:r></w:p>',
        $xml,
        1,
    ) ?? $xml);
}

/**
 * @param  array<string, mixed>  $payload
 */
function uploadTemplate(Membership $admin, array $payload): TestResponse
{
    return inSpace($admin)->post('/api/v1/platform/document-templates', $payload, ['Accept' => 'application/json']);
}

function contractFromActiveTemplate(Membership $agent): GeneratedDocument
{
    $member = validatedMember($agent);
    inSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/documents", ['type' => 'membership_contract'])->assertStatus(202);

    return GeneratedDocument::query()->withoutGlobalScopes()->with(['pdf', 'docx'])->where('member_id', $member->id)->latest('version')->firstOrFail();
}

it('RG-DATA-03 remplace le modèle provisoire du contrat par le modèle réel sans toucher aux contrats déjà émis', function () {
    $admin = templatesAdmin();
    $agent = documentAgent();
    $provisional = DocumentTemplate::query()->where('type', DocumentType::MembershipContract)->sole();
    $issued = contractFromActiveTemplate($agent);
    $issuedFiles = [$issued->pdf_file_id, $issued->docx_file_id, $issued->sha256];

    $id = uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '2026.1', 'file' => officialContract(), 'notes' => 'Modèle validé par le SP-CGA'])
        ->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.check.valid', true)
        ->assertJsonPath('data.check.missing', [])
        ->assertJsonPath('data.check.unknown', [])
        ->assertJsonPath('data.can.activate', true)
        ->json('data.id');

    expect(contractFromActiveTemplate($agent)->template_id)->toBe($provisional->id);

    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/preview")->assertOk()
        ->assertJsonPath('data.file.name', 'contrat-dadhesion-v20261-apercu.pdf');
    expect(GeneratedDocument::query()->withoutGlobalScopes()->where('template_id', $id)->exists())->toBeFalse();

    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", ['approved_by_cncga_on' => today()->toDateString()])->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.approved_by_cncga_at', today()->toDateString())
        ->assertJsonPath('data.can.activate', false);
    expect($provisional->refresh()->is_active)->toBeFalse();

    $next = contractFromActiveTemplate($agent);
    expect($next->template_id)->toBe($id)
        ->and(documentBody($next))->toContain('MODELE OFFICIEL DU RESEAU')->not->toContain('${');

    $issued = storedDocument($issued->id);
    expect($issued->template_id)->toBe($provisional->id)
        ->and([$issued->pdf_file_id, $issued->docx_file_id, $issued->sha256])->toBe($issuedFiles)
        ->and(documentBody($issued))->not->toContain('MODELE OFFICIEL DU RESEAU');

    inSpace($agent)->postJson("/api/v1/cga/documents/{$issued->id}/regenerate")->assertStatus(202);
    expect(GeneratedDocument::query()->withoutGlobalScopes()->where('replaces_document_id', $issued->id)->sole()->template_id)->toBe($id);

    inSpace($admin)->getJson('/api/v1/platform/document-templates')->assertOk()
        ->assertJsonFragment(['id' => $provisional->id, 'documents_count' => 2, 'is_active' => false])
        ->assertJsonFragment(['id' => $id, 'documents_count' => 2, 'is_active' => true]);
});

it('F-ADM-02 liste les variables manquantes ou inconnues : version ni prévisualisable ni activable', function () {
    $admin = templatesAdmin();
    $file = changedTemplate('membership_contract', static fn (string $xml): string => str_replace('${member_name}', '${nom_inconnu}', $xml));

    $id = uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '2', 'file' => $file])->assertCreated()
        ->assertJsonPath('data.check.valid', false)
        ->assertJsonPath('data.check.missing', ['member_name'])
        ->assertJsonPath('data.check.unknown', ['nom_inconnu'])
        ->assertJsonPath('data.can.preview', false)
        ->assertJsonPath('data.can.activate', false)
        ->json('data.id');

    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/preview")->assertStatus(409)->assertJsonPath('code', 'template_variables_mismatch');
    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", ['approved_by_cncga_on' => today()->toDateString()])->assertStatus(409)
        ->assertJsonPath('code', 'template_variables_mismatch')
        ->assertJsonPath('details.missing', ['member_name']);
    expect(DocumentTemplate::query()->where('type', DocumentType::MembershipContract)->where('is_active', true)->sole()->version)->toBe(TemplateSeeder::VERSION);
});

it('accepte une version du classeur officiel (XLSX), relève ses variables et la prévisualise', function () {
    $path = (string) config('documents.templates.quarterly_report_xlsx.path');
    $admin = templatesAdmin();

    $id = uploadTemplate($admin, ['type' => 'quarterly_report_xlsx', 'version' => '2', 'file' => UploadedFile::fake()->createWithContent('classeur.xlsx', (string) file_get_contents($path))])
        ->assertCreated()
        ->assertJsonPath('data.check.valid', true)
        ->assertJsonPath('data.type', 'quarterly_report_xlsx')
        ->json('data.id');

    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/preview")->assertOk()->assertJsonPath('data.file.name', 'rapport-trimestriel-classeur-officiel-v2-apercu.pdf');
});

it('refuse un type sans modèle, un fichier du mauvais format ou illisible, une version en double, une activation sans validation du CNCGA', function () {
    $admin = templatesAdmin();

    uploadTemplate($admin, ['type' => 'uploaded', 'version' => '2', 'file' => officialContract()])->assertUnprocessable()->assertJsonValidationErrors('type');
    uploadTemplate($admin, ['type' => 'membership_contract', 'version' => TemplateSeeder::VERSION, 'file' => officialContract()])->assertUnprocessable()->assertJsonValidationErrors('version');
    uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '../2', 'file' => officialContract()])->assertUnprocessable()->assertJsonValidationErrors('version');
    $xlsx = realUpload((string) file_get_contents((string) config('documents.templates.quarterly_report_xlsx.path')), 'contrat.docx');
    uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '2', 'file' => $xlsx])->assertUnprocessable()->assertJsonValidationErrors('file');
    uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '2', 'file' => realUpload("%PDF-1.4\n%%EOF", 'contrat.docx')])->assertUnprocessable()->assertJsonValidationErrors('file');
    uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '2', 'file' => withoutBody('membership_contract')])->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => __('documents.template_unreadable')]);

    $id = uploadTemplate($admin, ['type' => 'membership_contract', 'version' => '2', 'file' => officialContract()])->assertCreated()->json('data.id');
    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", [])->assertUnprocessable()->assertJsonValidationErrors('approved_by_cncga_on');
    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", ['approved_by_cncga_on' => today()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('approved_by_cncga_on');
    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", ['approved_by_cncga_on' => today()->toDateString()])->assertOk();
    inSpace($admin)->postJson("/api/v1/platform/document-templates/{$id}/activate", ['approved_by_cncga_on' => today()->toDateString()])->assertStatus(409)->assertJsonPath('code', 'template_already_active');
});

it('réserve les modèles à l\'administrateur de la plateforme', function () {
    $template = DocumentTemplate::query()->where('type', DocumentType::MembershipContract)->sole();

    foreach ([Membership::factory()->role(Role::CncgaPresident)->create(), documentAgent()] as $other) {
        inSpace($other)->getJson('/api/v1/platform/document-templates')->assertForbidden();
        uploadTemplate($other, ['type' => 'membership_contract', 'version' => '2', 'file' => officialContract()])->assertForbidden();
        inSpace($other)->postJson("/api/v1/platform/document-templates/{$template->id}/preview")->assertForbidden();
        inSpace($other)->postJson("/api/v1/platform/document-templates/{$template->id}/activate", ['approved_by_cncga_on' => today()->toDateString()])->assertForbidden();
    }
});
