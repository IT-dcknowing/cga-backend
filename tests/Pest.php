<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Files\PdfConverter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Spa;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Tests Pest (02-TRD §13)
|--------------------------------------------------------------------------
|
| Les tests de tests/Feature démarrent l'application Laravel, sur PostgreSQL
| (base cga_plateforme_test), migrée au premier accès ; les files, le cache et
| les sessions y vivent aussi (02-TRD §13). Ceux de tests/Unit, tests/Arch et
| tests/Tooling sont des tests PHP purs, sans application.
|
*/

pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');

/**
 * Requête du SPA connecté dans l'espace de ce rattachement (02-TRD §6.2).
 */
function inSpace(Membership $membership): TestCase
{
    return test()->actingAs($membership->user)
        ->withHeaders(Spa::headers())
        ->withSession([TenantContext::SESSION_KEY => $membership->id]);
}

/**
 * Parties XML d'un modèle (corps, en-têtes, pieds de page, propriétés, feuilles, chaînes partagées…).
 *
 * @return array<string, string>
 */
function templateParts(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (preg_match('/\.(xml|rels)$/', $name) === 1) {
            // Les espaces de noms XML de Microsoft contiennent des années (…/2018/wordml) : retirés.
            $parts[$name] = (string) preg_replace('/xmlns(:\w+)?="[^"]*"|mc:Ignorable="[^"]*"/', '', (string) $zip->getFromName($name));
        }
    }
    $zip->close();

    return $parts;
}

/**
 * Agent d'un CGA dont la fiche est complète (RG-ORG-04).
 */
function documentAgent(Role $role = Role::CgaManager, ?string $organizationId = null): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $organizationId ?? Organization::factory()->profileComplete()->create()->id]);
}

/**
 * Dossier validé du portefeuille CGA du centre de cet agent.
 *
 * @param  array<string, mixed>  $attributes
 */
function validatedMember(Membership $agent, array $attributes = []): Member
{
    return Member::factory()->active()->create(['organization_id' => $agent->organization_id, ...$attributes]);
}

/**
 * Conversion PDF simulée : un PDF minimal propre au document, sans LibreOffice.
 */
function fakePdfConverter(): void
{
    app()->instance(PdfConverter::class, new class implements PdfConverter
    {
        public function convert(string $sourcePath): string
        {
            return "%PDF-1.4\n% document de test ".hash_file('sha256', $sourcePath)."\n%%EOF";
        }
    });
}

/**
 * Document lu hors de tout espace (vérifications des tests).
 */
function storedDocument(string $id): GeneratedDocument
{
    return GeneratedDocument::query()->withoutGlobalScopes()->with(['pdf', 'docx'])->findOrFail($id);
}

/**
 * Texte du corps du DOCX d'un document produit (variables remplies, valeurs présentes).
 */
function documentBody(GeneratedDocument $document): string
{
    $path = storage_path('framework/testing/document-'.$document->id.'.docx');
    file_put_contents($path, (string) Storage::get($document->docx?->path ?? ''));
    $body = templateParts($path)['word/document.xml'];
    unlink($path);

    return $body;
}

/**
 * Somme due avec la TVA par défaut de 18 % en sus du hors taxe (factures, étape 3.13).
 *
 * @param  array<string, mixed>  $attributes
 */
function taxedCharge(Member $member, int $amountExclTax, ChargeKind $kind = ChargeKind::EntryFee, array $attributes = []): MemberCharge
{
    $tax = (int) round($amountExclTax * 0.18);

    return MemberCharge::factory()->create([
        'member_id' => $member->id, 'kind' => $kind, 'amount_excl_tax' => $amountExclTax, 'tax_rate' => 18, 'tax_amount' => $tax, 'amount' => $amountExclTax + $tax,
        ...($kind === ChargeKind::Fees ? ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-10-01'] : []),
        ...$attributes,
    ]);
}

/**
 * Espace de l'agent, actif à l'instant (le délai d'inactivité de la session suit les voyages dans le temps).
 */
function invoiceSpace(Membership $agent): TestCase
{
    return inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

/**
 * @param  list<string>  $chargeIds
 * @param  array<string, mixed>  $extra
 */
function issueInvoice(Membership $agent, Member $member, array $chargeIds, array $extra = []): TestResponse
{
    return invoiceSpace($agent)->postJson('/api/v1/cga/invoices', ['member_id' => $member->id, 'charge_ids' => $chargeIds, ...$extra]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function certifyManually(Membership $agent, string $invoiceId, array $payload): TestResponse
{
    return invoiceSpace($agent)->post("/api/v1/cga/invoices/{$invoiceId}/manual-certification", $payload, ['Accept' => 'application/json']);
}

function certifiedPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('facture-certifiee.pdf', "%PDF-1.4\n% facture certifiée FNE\n%%EOF");
}
