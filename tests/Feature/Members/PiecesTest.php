<?php

use App\Domain\Members\Actions\CreateExpectedPieces;
use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Pièces du dossier (06-IMPLEMENTATION-PLAN étape 2.5, F-PJ-01, RG-PJ-01, 02) : pièces attendues,
 * dépôt, statut reçu ou expiré, état « pièces en attente » du dossier.
 */

beforeEach(function () {
    Storage::fake();
});

function piecesAgent(Role $role = Role::CgaManager, ?string $organizationId = null): Membership
{
    return Membership::factory()->role($role)->create($organizationId === null ? [] : ['organization_id' => $organizationId]);
}

/**
 * Dossier validé du centre, avec ses pièces attendues.
 */
function piecesMember(Membership $agent, array $overrides = []): Member
{
    $member = Member::factory()->active()->create(['organization_id' => $agent->organization_id, ...$overrides]);
    app(TenantContext::class)->runAs($agent, fn () => app(CreateExpectedPieces::class)->execute($member));

    return $member;
}

function depositPiece(Membership $agent, Member $member, string $type, array $overrides = []): TestResponse
{
    return inSpace($agent)->post("/api/v1/cga/members/{$member->id}/pieces/{$type}", [
        'file' => UploadedFile::fake()->createWithContent("{$type}.pdf", "%PDF-1.4\n%%EOF"),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

function storedPiece(Membership $agent, Member $member, PieceType $type): MemberPiece
{
    return app(TenantContext::class)->runAs($agent, fn () => MemberPiece::query()->where('member_id', $member->id)->where('piece_type', $type)->sole());
}

it('RG-PJ-01 liste les pièces attendues et dépose une pièce : reçue, fichier remplacé et conservé', function () {
    $manager = piecesAgent();
    $member = piecesMember($manager);

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/pieces")
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0.type', 'rccm')
        ->assertJsonPath('data.0.status', 'expected')
        ->assertJsonPath('data.0.status_label', 'Attendue')
        ->assertJsonPath('data.5.type', 'signed_cga_contract')
        ->assertJsonPath('data.0.can.upload', true);

    depositPiece($manager, $member, 'rccm')
        ->assertCreated()
        ->assertJsonPath('data.type', 'rccm')
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.file.name', 'rccm.pdf')
        ->assertJsonPath('data.received_at', today()->toDateString());
    $first = storedPiece($manager, $member, PieceType::Rccm)->file_id;

    depositPiece($manager, $member, 'rccm')->assertCreated();

    expect(storedPiece($manager, $member, PieceType::Rccm)->file_id)->not->toBe($first)
        ->and(app(TenantContext::class)->runAs($manager, fn () => MemberPiece::query()->where('member_id', $member->id)->count()))->toBe(6)
        ->and(DB::table('files')->where('id', $first)->exists())->toBeTrue()
        ->and($member->fresh()?->pieces_status->value)->toBe('pending');
});

it('RG-PJ-02 passe le dossier « pièces complètes » quand tout est reçu, et « en attente » à l\'expiration d\'une pièce datée', function () {
    $manager = piecesAgent();
    $member = piecesMember($manager);
    foreach (['rccm', 'dfe', 'id_document', 'statutes', 'location_plan', 'signed_cga_contract'] as $type) {
        depositPiece($manager, $member, $type)->assertCreated();
    }
    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")->assertJsonPath('data.pieces_status', 'complete');

    depositPiece($manager, $member, 'id_document', ['expires_at' => today()->subDay()->toDateString()])->assertCreated()->assertJsonPath('data.status', 'expired');
    expect($member->fresh()?->pieces_status->value)->toBe('pending');

    depositPiece($manager, $member, 'id_document', ['expires_at' => today()->addDays(10)->toDateString()])->assertCreated()->assertJsonPath('data.status', 'received');
    expect($member->fresh()?->pieces_status->value)->toBe('complete');

    $this->artisan('statuses:refresh')->expectsOutput('0 pièce(s) expirée(s).')->assertSuccessful();
    $this->travel(11)->days();
    $this->artisan('statuses:refresh')->expectsOutput('1 pièce(s) expirée(s).')->assertSuccessful();

    expect(storedPiece($manager, $member, PieceType::IdDocument)->status)->toBe(PieceStatus::Expired)
        ->and($member->fresh()?->pieces_status->value)->toBe('pending');
});

it('fait du contrat CGA signé déposé l\'exemplaire du dossier, même sur un brouillon, reçu à la validation', function () {
    $manager = piecesAgent();
    $draft = Member::factory()->create(['organization_id' => $manager->organization_id]);

    inSpace($manager)->getJson("/api/v1/cga/members/{$draft->id}/pieces")->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.5.status', 'expected');
    depositPiece($manager, $draft, 'signed_cga_contract')->assertCreated();

    $draft->refresh();
    expect($draft->cga_contract_file_id)->not->toBeNull()
        ->and($draft->pieces_status->value)->toBe('pending');
    app(TenantContext::class)->runAs($manager, fn () => app(CreateExpectedPieces::class)->execute($draft));
    expect(storedPiece($manager, $draft, PieceType::SignedCgaContract)->status)->toBe(PieceStatus::Received)
        ->and(storedPiece($manager, $draft, PieceType::SignedCgaContract)->file_id)->toBe($draft->cga_contract_file_id);
});

it('montre en dernier, sans dépôt possible, une pièce devenue sans objet', function () {
    $manager = piecesAgent();
    $member = piecesMember($manager);
    app(TenantContext::class)->runAs($manager, function () use ($member): void {
        MemberPiece::query()->where('member_id', $member->id)->where('piece_type', PieceType::SignedCgaContract)->update(['status' => PieceStatus::NotApplicable]);
        Member::query()->whereKey($member->id)->update(['portfolio' => 'cabinet', 'client_number' => 'CAB-TST-00001']);
    });

    inSpace($manager)->getJson("/api/v1/cga/members/{$member->id}/pieces")
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.5.type', 'signed_cga_contract')
        ->assertJsonPath('data.5.status', 'not_applicable')
        ->assertJsonPath('data.5.can.upload', false);
    depositPiece($manager, $member, 'signed_cga_contract')->assertStatus(409)->assertJsonPath('code', 'piece_not_applicable');
});

it('refuse une pièce non attendue, un fichier non permis, un dossier radié, un rôle sans le droit', function () {
    $manager = piecesAgent();
    $individual = piecesMember($manager, ['entity_type' => EntityType::Individual]);

    depositPiece($manager, $individual, 'statutes')->assertStatus(409)->assertJsonPath('code', 'piece_not_applicable');
    depositPiece($manager, $individual, 'rccm', ['file' => UploadedFile::fake()->createWithContent('note.txt', 'texte')])->assertUnprocessable()->assertJsonValidationErrors('file');
    depositPiece($manager, $individual, 'rccm', ['expires_at' => '31/12/2026'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    depositPiece($manager, $individual, 'passeport')->assertNotFound();
    depositPiece($manager, piecesMember($manager, ['status' => MemberStatus::Radiated]), 'rccm')->assertStatus(409)->assertJsonPath('code', 'member_closed');

    $billing = piecesAgent(Role::CgaBillingOfficer, $manager->organization_id);
    depositPiece($billing, $individual, 'rccm')->assertForbidden();
    depositPiece(piecesAgent(Role::CgaSecretary, $manager->organization_id), $individual, 'rccm')->assertForbidden();
    inSpace($billing)->getJson("/api/v1/cga/members/{$individual->id}/pieces")->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.can.upload', false);
    inSpace(piecesAgent())->getJson("/api/v1/cga/members/{$individual->id}/pieces")->assertNotFound();
});
