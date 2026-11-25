<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Concerns\Actions\SendStaleConcernAlerts;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Models\ConcernMessage;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
 * Préoccupations (06-IMPLEMENTATION-PLAN étape 7.1, PRD M10, RG-PRE-01 à 03) : demande de l'adhérent à son
 * CGA (F-PRE-01), fil, notes internes, statuts et réouverture (F-PRE-02), transfert au CNCGA avec copie de
 * l'échange (F-PRE-03), délais visés et alerte à 15 jours, tableaux des délais du centre et du réseau
 * (F-PRE-04), demande de document (F-DOC-06). Décision 0012.
 */

beforeEach(function () {
    Storage::fake();
    config(['queue.default' => 'sync']);
    $this->travelTo('2026-11-04 09:00:00');
    Organization::factory()->cncga()->create();
    $this->cncgaManager = Membership::factory()->role(Role::CncgaManager)->create();
    $this->board = Membership::factory()->role(Role::CncgaViewer)->create();
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $this->cga->id]);
    $this->secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->cga->id]);
    $this->billing = Membership::factory()->role(Role::CgaBillingOfficer)->create(['organization_id' => $this->cga->id]);
    $this->member = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'INC SARL']);
    $this->owner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $this->member->id]);
});

/**
 * Requête du SPA dans cet espace, session active à l'heure du test (les tests avancent dans le temps).
 */
function concernSpace(Membership $membership): TestCase
{
    return inSpace($membership)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

/**
 * Fichier envoyé tel quel : son type est lu dans son contenu, comme pour un vrai envoi.
 */
function concernUpload(string $name, string $contents): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'concern');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Types des notifications reçues dans cet espace, par ordre alphabétique (l'heure des tests est figée).
 *
 * @return list<string>
 */
function concernNotices(Membership $membership): array
{
    $types = $membership->user->notifications()->where('data->membership_id', $membership->id)->pluck('type')->all();
    sort($types);

    return $types;
}

function memberConcern(Membership $owner, array $overrides = [])
{
    return concernSpace($owner)->post('/api/v1/member/concerns', [
        'category' => 'tax', 'subject' => 'Patente 2026', 'message' => 'Quel montant de patente pour 2026 ?', ...$overrides,
    ]);
}

function storedConcern(string $id): Concern
{
    return Tenancy::bypass(static fn (): Concern => Concern::query()->findOrFail($id), 'Test.');
}

function concernPdf(string $name = 'avis.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
}

describe('soumettre une préoccupation (F-PRE-01)', function () {
    it('l\'adhérent écrit à son CGA avec une pièce jointe : ouverte, délai visé de 7 jours, agents avertis', function () {
        $response = memberConcern($this->owner, ['attachments' => [concernPdf()]])
            ->assertCreated()
            ->assertJsonPath('data.level', 'member_to_cga')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.status_label', 'Ouverte')
            ->assertJsonPath('data.category_label', 'Fiscalité')
            ->assertJsonPath('data.target_response_days', 7)
            ->assertJsonPath('data.messages.0.body', 'Quel montant de patente pour 2026 ?')
            ->assertJsonPath('data.messages.0.side', 'requester')
            ->assertJsonPath('data.messages.0.attachments.0.name', 'avis.pdf')
            ->assertJsonPath('data.messages.0.attachments.0.mime_type', 'application/pdf')
            ->assertJsonPath('data.can.reopen', false)
            ->assertJsonMissingPath('data.member')
            ->assertJsonMissingPath('data.messages.0.internal');

        expect(concernNotices($this->manager))->toBe(['concern_new'])
            ->and(concernNotices($this->secretary))->toBe(['concern_new'])
            ->and(concernNotices($this->billing))->toBe([])
            ->and(concernNotices($this->cncgaManager))->toBe([])
            ->and(storedConcern($response->json('data.id')))->member_id->toBe($this->member->id)->organization_id->toBe($this->cga->id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'member_concerns.store']);
    });

    it('refuse une demande incomplète, une catégorie inconnue, un fichier qui n\'est pas un PDF ou une image (422)', function () {
        memberConcern($this->owner, ['subject' => '', 'message' => ''])->assertUnprocessable()->assertJsonValidationErrors(['subject', 'message']);
        memberConcern($this->owner, ['category' => 'urgent'])->assertUnprocessable()->assertJsonValidationErrors('category');
        memberConcern($this->owner, ['subject' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors('subject');
        memberConcern($this->owner, ['attachments' => [concernUpload('script.pdf', '<?php echo 1;')]])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        app(SettingsService::class)->set('concerns.max_attachments', 1);
        memberConcern($this->owner, ['attachments' => [concernPdf(), concernPdf('b.pdf')]])->assertUnprocessable()->assertJsonValidationErrors('attachments');
    });

    it('ne lit ni le niveau, ni le dossier, ni le centre dans la requête', function () {
        $other = Member::factory()->active()->create(['organization_id' => $this->cga->id]);
        $id = memberConcern($this->owner, ['level' => 'cga_to_cncga', 'member_id' => $other->id, 'organization_id' => Organization::factory()->create()->id])
            ->assertCreated()->json('data.id');

        expect(storedConcern($id))->level->toBe(ConcernLevel::MemberToCga)->member_id->toBe($this->member->id)->organization_id->toBe($this->cga->id);
    });

    it('le gérant écrit au CNCGA : délai visé de 15 jours, le CNCGA est averti ; le secrétaire ne le peut pas (403)', function () {
        concernSpace($this->manager)->post('/api/v1/cga/concerns', ['category' => 'legal', 'subject' => 'Agrément', 'message' => 'Renouvellement de l\'agrément ?'])
            ->assertCreated()->assertJsonPath('data.level', 'cga_to_cncga')->assertJsonPath('data.target_response_days', 15)->assertJsonPath('data.member', null);

        expect(concernNotices($this->cncgaManager))->toBe(['concern_new'])
            ->and(concernNotices($this->board))->toBe([]);
        concernSpace($this->secretary)->post('/api/v1/cga/concerns', ['category' => 'legal', 'subject' => 'X', 'message' => 'Y'])->assertForbidden();
        concernSpace($this->owner)->post('/api/v1/cga/concerns', ['category' => 'legal', 'subject' => 'X', 'message' => 'Y'])->assertForbidden();
        concernSpace($this->manager)->post('/api/v1/member/concerns', ['category' => 'legal', 'subject' => 'X', 'message' => 'Y'])->assertForbidden();
    });

    it('un adhérent radié en consultation seule n\'écrit plus à son CGA (403)', function () {
        $this->owner->status = MembershipStatus::ReadOnly;
        $this->owner->save();

        memberConcern($this->owner)->assertForbidden();
        concernSpace($this->owner)->getJson('/api/v1/member/concerns')->assertForbidden();
    });

    it('F-DOC-06 · « Demander un document » crée une préoccupation « Demande de document »', function () {
        concernSpace($this->owner)->postJson('/api/v1/member/document-requests', ['type' => 'attestation'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'document_request')
            ->assertJsonPath('data.subject', 'Demande de document : Attestation')
            ->assertJsonPath('data.messages.0.body', 'Merci de me transmettre ce document : attestation.');
        concernSpace($this->owner)->postJson('/api/v1/member/document-requests', ['type' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('message');
        concernSpace($this->owner)->postJson('/api/v1/member/document-requests', ['type' => 'other', 'message' => 'Le relevé des honoraires 2025'])
            ->assertCreated()->assertJsonPath('data.subject', 'Demande de document : Autre document')->assertJsonPath('data.messages.0.body', 'Le relevé des honoraires 2025');
        concernSpace($this->manager)->postJson('/api/v1/member/document-requests', ['type' => 'attestation'])->assertForbidden();

        expect(concernNotices($this->manager))->toBe(['concern_new', 'concern_new']);
    });
});

describe('traiter une préoccupation (F-PRE-02)', function () {
    beforeEach(function () {
        $this->concernId = memberConcern($this->owner)->json('data.id');
    });

    it('le CGA voit la demande reçue et son dossier ; une réponse avertit l\'adhérent et n\'attend plus de réponse', function () {
        concernSpace($this->secretary)->getJson('/api/v1/cga/concerns')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member.company_name', 'INC SARL')
            ->assertJsonPath('data.0.awaiting_reply', true)
            ->assertJsonPath('data.0.stale', false);

        concernSpace($this->secretary)->post("/api/v1/cga/concerns/{$this->concernId}/messages", ['message' => 'Elle est de 50 000 F.', 'attachments' => [concernPdf('bareme.pdf')]])
            ->assertCreated()
            ->assertJsonPath('data.awaiting_reply', false)
            ->assertJsonPath('data.messages.1.side', 'handler')
            ->assertJsonPath('data.messages.1.internal', false)
            ->assertJsonPath('data.messages.1.attachments.0.name', 'bareme.pdf');

        expect(concernNotices($this->owner))->toBe(['concern_updated'])
            ->and(storedConcern($this->concernId)->first_response_at)->not->toBeNull();
        concernSpace($this->owner)->getJson("/api/v1/member/concerns/{$this->concernId}")->assertOk()->assertJsonCount(2, 'data.messages');
    });

    it('une note interne reste invisible de l\'adhérent, sans notification ; l\'adhérent ne peut pas en écrire (403)', function () {
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/messages", ['message' => 'Vérifier la patente 2025.', 'internal' => true])
            ->assertCreated()->assertJsonPath('data.messages.1.internal', true)->assertJsonPath('data.awaiting_reply', true);

        expect(concernNotices($this->owner))->toBe([]);
        concernSpace($this->owner)->getJson("/api/v1/member/concerns/{$this->concernId}")
            ->assertOk()->assertJsonCount(1, 'data.messages')->assertJsonMissing(['body' => 'Vérifier la patente 2025.']);
        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$this->concernId}/messages", ['message' => 'Note', 'internal' => true])->assertForbidden();
        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$this->concernId}/messages", ['message' => ''])->assertUnprocessable()->assertJsonValidationErrors('message');
    });

    it('l\'adhérent relance : la demande attend une réponse et les agents sont avertis', function () {
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/messages", ['message' => 'Précisez votre régime.'])->assertCreated();
        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$this->concernId}/messages", ['message' => 'TEE.'])->assertCreated()->assertJsonMissingPath('data.awaiting_reply');

        expect(storedConcern($this->concernId)->awaiting_reply_since)->not->toBeNull()
            ->and(concernNotices($this->secretary))->toBe(['concern_followup', 'concern_new']);
    });

    it('statuts : En examen, puis Rejetée avec motif ; plus de message avant réouverture (409) ; l\'adhérent rouvre', function () {
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'in_review'])
            ->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.messages.1.system', true)
            ->assertJsonPath('data.messages.1.body', 'Statut de la demande : En examen.');
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'open'])->assertUnprocessable()->assertJsonValidationErrors('status');
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'rejected', 'reason' => 'Hors du champ du CGA'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Hors du champ du CGA')
            ->assertJsonPath('data.can.reply', false)->assertJsonPath('data.can.change_status', false);

        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'resolved'])->assertStatus(409)->assertJsonPath('code', 'concern_status_invalid');
        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$this->concernId}/messages", ['message' => 'Pourquoi ?'])->assertStatus(409)->assertJsonPath('code', 'concern_closed');
        concernSpace($this->owner)->getJson("/api/v1/member/concerns/{$this->concernId}")->assertJsonPath('data.can.reopen', true)->assertJsonPath('data.can.reply', false);
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/reopen")->assertForbidden();

        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$this->concernId}/reopen")
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.reopen_count', 1)->assertJsonPath('data.rejection_reason', null);
        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$this->concernId}/reopen")->assertStatus(409)->assertJsonPath('code', 'concern_not_closed');

        expect(concernNotices($this->owner))->toBe(['concern_updated', 'concern_updated'])
            ->and(concernNotices($this->manager))->toBe(['concern_followup', 'concern_new']);
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('data.status_label', 'Traitée')->assertJsonPath('data.resolved_at', now()->toIso8601String());
    });

    it('filtres de l\'onglet « Reçues » : statut, catégorie, dossier, en attente ; tri et filtre hors liste blanche refusés', function () {
        $other = Member::factory()->active()->create(['organization_id' => $this->cga->id]);
        $otherOwner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $other->id]);
        $this->travel(1)->minutes();
        memberConcern($otherOwner, ['category' => 'social']);
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'resolved'])->assertOk();

        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[status]=open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'social');
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[category]=tax')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->concernId);
        concernSpace($this->manager)->getJson("/api/v1/cga/concerns?filter[member_id]={$this->member->id}")->assertJsonCount(1, 'data');
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[awaiting]=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'social');
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?sort=created_at&per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->concernId);
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?sort=subject')->assertUnprocessable();
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[subject]=x')->assertUnprocessable();
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[level]=autre')->assertUnprocessable();
        concernSpace($otherOwner)->getJson('/api/v1/member/concerns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'social');
    });

    it('droits : sans concerns.reply, ni liste ni demande (403) ; la demande d\'un autre adhérent n\'existe pas (404)', function () {
        $other = Member::factory()->active()->create(['organization_id' => $this->cga->id]);
        $otherOwner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $other->id]);

        concernSpace($this->billing)->getJson('/api/v1/cga/concerns')->assertForbidden();
        concernSpace($this->billing)->getJson("/api/v1/cga/concerns/{$this->concernId}")->assertNotFound();
        concernSpace($otherOwner)->getJson("/api/v1/member/concerns/{$this->concernId}")->assertNotFound();
        concernSpace($otherOwner)->postJson("/api/v1/member/concerns/{$this->concernId}/messages", ['message' => 'Bonjour'])->assertNotFound();
        concernSpace($this->owner)->getJson("/api/v1/cga/concerns/{$this->concernId}")->assertForbidden();
        concernSpace($this->owner)->getJson('/api/v1/member/concerns/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
        concernSpace($this->secretary)->getJson('/api/v1/cga/concerns?filter[level]=cga_to_cncga')->assertForbidden();
        concernSpace($this->secretary)->getJson('/api/v1/cga/concerns/indicators')->assertForbidden();
    });

    it('RG-PTF-02 · une demande d\'un dossier Cabinet n\'est vue et annoncée qu\'aux agents qui voient ce portefeuille', function () {
        $cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $this->cga->id]);
        $client = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $cabinet->id]);
        $id = memberConcern($client)->assertCreated()->json('data.id');

        expect(concernNotices($this->secretary))->toBe(['concern_new', 'concern_new']);

        Tenancy::bypass(function (): void {
            $override = new UserPermissionOverride;
            $override->forceFill(['organization_id' => $this->cga->id, 'membership_id' => $this->secretary->id, 'permission' => 'members.cabinet.view', 'granted' => false, 'updated_by' => $this->manager->user_id])->save();
        }, 'Test.');
        app(PermissionResolver::class)->flush();
        concernSpace($this->secretary)->getJson('/api/v1/cga/concerns')->assertOk()->assertJsonCount(1, 'data');
        concernSpace($this->secretary)->getJson("/api/v1/cga/concerns/{$id}")->assertNotFound();
        memberConcern($client)->assertCreated();
        expect(concernNotices($this->secretary))->toBe(['concern_new', 'concern_new']);
    });
});

describe('transfert au CNCGA (F-PRE-03)', function () {
    beforeEach(function () {
        $this->concernId = memberConcern($this->owner, ['attachments' => [concernPdf('releve.pdf')]])->json('data.id');
        concernSpace($this->secretary)->postJson("/api/v1/cga/concerns/{$this->concernId}/messages", ['message' => 'Nous transmettons.'])->assertCreated();
        concernSpace($this->secretary)->postJson("/api/v1/cga/concerns/{$this->concernId}/messages", ['message' => 'Dossier sensible.', 'internal' => true])->assertCreated();
    });

    it('crée la demande liée au CNCGA avec la copie de l\'échange, sans nom ni dossier ; l\'origine passe « En examen »', function () {
        concernSpace($this->secretary)->postJson("/api/v1/cga/concerns/{$this->concernId}/transfer", ['message' => 'Avis du CNCGA ?'])->assertForbidden();
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/transfer", ['message' => ''])->assertUnprocessable()->assertJsonValidationErrors('message');

        $origin = concernSpace($this->manager)->post("/api/v1/cga/concerns/{$this->concernId}/transfer", ['message' => 'Avis du CNCGA ?', 'attachments' => [concernPdf('note.pdf')]])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.transferred', true)
            ->assertJsonPath('data.transfer.status', 'open')
            ->assertJsonPath('data.can.transfer', false);
        $networkId = $origin->json('data.transfer.id');

        $network = concernSpace($this->cncgaManager)->getJson("/api/v1/network/concerns/{$networkId}")
            ->assertOk()
            ->assertJsonPath('data.level', 'cga_to_cncga')
            ->assertJsonPath('data.from_transfer', true)
            ->assertJsonPath('data.organization.id', $this->cga->id)
            ->assertJsonPath('data.subject', 'Patente 2026')
            ->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.0.author.name', 'Adhérent')
            ->assertJsonPath('data.messages.0.copied', true)
            ->assertJsonPath('data.messages.0.attachments', [])
            ->assertJsonPath('data.messages.1.author.name', 'CGA')
            ->assertJsonPath('data.messages.2.body', 'Avis du CNCGA ?')
            ->assertJsonPath('data.messages.2.attachments.0.name', 'note.pdf')
            ->assertJsonMissingPath('data.member');
        expect(json_encode($network->json()))->not->toContain('INC SARL')->not->toContain('Dossier sensible')->not->toContain($this->owner->user->last_name)
            ->and(storedConcern($networkId))->member_id->toBeNull()->transferred_from_concern_id->toBe($this->concernId)
            ->and(concernNotices($this->cncgaManager))->toBe(['concern_new'])
            ->and(concernNotices($this->owner))->toBe(['concern_updated', 'concern_updated']);

        concernSpace($this->owner)->getJson("/api/v1/member/concerns/{$this->concernId}")
            ->assertJsonPath('data.status_label', 'En examen')->assertJsonPath('data.transferred', true)->assertJsonMissingPath('data.transfer')
            ->assertJsonPath('data.messages.2.body', 'Demande transmise au CNCGA.');
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[level]=cga_to_cncga')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $networkId);
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/transfer", ['message' => 'Encore'])->assertStatus(409)->assertJsonPath('code', 'concern_already_transferred');
    });

    it('une demande traitée ne se transmet pas (409)', function () {
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/status", ['status' => 'resolved'])->assertOk();

        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->concernId}/transfer", ['message' => 'Avis ?'])->assertStatus(409)->assertJsonPath('code', 'concern_closed');
    });
});

describe('demandes des CGA au CNCGA (F-PRE-02)', function () {
    beforeEach(function () {
        $this->networkId = concernSpace($this->manager)->postJson('/api/v1/cga/concerns', ['category' => 'legal', 'subject' => 'Agrément', 'message' => 'Renouvellement ?'])->json('data.id');
    });

    it('le CNCGA répond, note en interne, rejette ; le CGA est averti, ne voit pas la note et rouvre', function () {
        concernSpace($this->board)->getJson('/api/v1/network/concerns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization.name', $this->cga->name);
        concernSpace($this->board)->postJson("/api/v1/network/concerns/{$this->networkId}/messages", ['message' => 'Lu'])->assertForbidden();

        concernSpace($this->cncgaManager)->postJson("/api/v1/network/concerns/{$this->networkId}/messages", ['message' => 'Dossier en cours au ministère.'])
            ->assertCreated()->assertJsonPath('data.messages.1.side', 'handler');
        concernSpace($this->cncgaManager)->postJson("/api/v1/network/concerns/{$this->networkId}/messages", ['message' => 'À suivre en bureau.', 'internal' => true])->assertCreated();
        concernSpace($this->cncgaManager)->postJson("/api/v1/network/concerns/{$this->networkId}/status", ['status' => 'rejected', 'reason' => 'Relève du ministère'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        expect(concernNotices($this->manager))->toBe(['concern_updated', 'concern_updated'])
            ->and(concernNotices($this->expert))->toBe(['concern_updated', 'concern_updated'])
            ->and(concernNotices($this->secretary))->toBe([]);
        concernSpace($this->expert)->getJson("/api/v1/cga/concerns/{$this->networkId}")
            ->assertOk()->assertJsonCount(3, 'data.messages')->assertJsonMissing(['body' => 'À suivre en bureau.'])->assertJsonMissingPath('data.awaiting_reply');
        concernSpace($this->expert)->postJson("/api/v1/cga/concerns/{$this->networkId}/status", ['status' => 'resolved'])->assertForbidden();
        concernSpace($this->expert)->postJson("/api/v1/cga/concerns/{$this->networkId}/reopen")->assertOk()->assertJsonPath('data.status', 'open');

        expect(concernNotices($this->cncgaManager))->toBe(['concern_followup', 'concern_new']);
        concernSpace($this->cncgaManager)->getJson('/api/v1/network/concerns?filter[status]=open&filter[organization_id]='.$this->cga->id)->assertJsonCount(1, 'data');
        concernSpace($this->cncgaManager)->getJson('/api/v1/network/concerns?filter[category]=tax')->assertJsonCount(0, 'data');
    });

    it('RG-SEC-01 · le CNCGA ne voit jamais une demande d\'adhérent (404) ; un CGA n\'utilise pas les routes du réseau (403)', function () {
        $memberConcern = memberConcern($this->owner)->json('data.id');

        concernSpace($this->cncgaManager)->getJson('/api/v1/network/concerns')->assertJsonCount(1, 'data');
        concernSpace($this->cncgaManager)->getJson("/api/v1/network/concerns/{$memberConcern}")->assertNotFound();
        concernSpace($this->cncgaManager)->postJson("/api/v1/network/concerns/{$memberConcern}/messages", ['message' => 'X'])->assertNotFound();
        concernSpace($this->cncgaManager)->getJson('/api/v1/network/concerns/pas-un-identifiant')->assertNotFound();
        concernSpace($this->manager)->getJson('/api/v1/network/concerns')->assertForbidden();
        concernSpace($this->manager)->getJson("/api/v1/network/concerns/{$this->networkId}")->assertForbidden();
        concernSpace($this->manager)->postJson("/api/v1/network/concerns/{$this->networkId}/status", ['status' => 'resolved'])->assertForbidden();
        concernSpace($this->cncgaManager)->getJson("/api/v1/cga/concerns/{$this->networkId}")->assertNotFound();
    });
});

describe('délais (RG-PRE-01, RG-PRE-02)', function () {
    it('RG-PRE-01 · délai écoulé et délai visé indicatifs, paramétrables, jamais bloquants', function () {
        app(SettingsService::class)->set('concerns.target_days', ['member_to_cga' => 3, 'cga_to_cncga' => 10]);
        $id = memberConcern($this->owner)->json('data.id');
        $this->travel(5)->days();

        concernSpace($this->manager)->getJson("/api/v1/cga/concerns/{$id}")
            ->assertJsonPath('data.target_response_days', 3)->assertJsonPath('data.elapsed_days', 5)->assertJsonPath('data.response_late', true)->assertJsonPath('data.can.reply', true);
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$id}/messages", ['message' => 'Réponse tardive.'])->assertCreated()->assertJsonPath('data.response_late', true);
        concernSpace($this->manager)->postJson('/api/v1/cga/concerns', ['category' => 'other', 'subject' => 'Q', 'message' => 'R'])->assertJsonPath('data.target_response_days', 10)->assertJsonPath('data.response_late', false);
        expect(fn () => app(SettingsService::class)->set('concerns.target_days', ['member_to_cga' => 0, 'cga_to_cncga' => 10]))->toThrow(ValidationException::class);
    });

    it('RG-PRE-02 · sans réponse depuis 15 jours : une alerte interne au CGA, une seule fois par attente, jamais au CNCGA', function () {
        $id = memberConcern($this->owner)->json('data.id');
        $answered = memberConcern($this->owner, ['subject' => 'Autre'])->json('data.id');
        $this->travel(14)->days();
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$answered}/messages", ['message' => 'Voici.'])->assertCreated();
        $this->artisan('reminders:concerns')->assertSuccessful()->expectsOutputToContain('0 demande(s)');

        $this->travel(1)->days();
        $this->artisan('reminders:concerns')->assertSuccessful()->expectsOutputToContain('1 demande(s)');
        $this->artisan('reminders:concerns')->assertSuccessful()->expectsOutputToContain('0 demande(s)');

        expect(concernNotices($this->manager))->toBe(['concern_new', 'concern_new', 'concern_stale'])
            ->and(concernNotices($this->billing))->toBe([])
            ->and(concernNotices($this->cncgaManager))->toBe([])
            ->and($this->manager->user->notifications()->where('type', 'concern_stale')->first()->data['body'])->toContain('1 demande(s) d\'adhérent attendent une réponse depuis plus de 15 jours');
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns?filter[stale]=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.stale', true);

        // Nouvelle attente : réponse, relance de l'adhérent, 15 jours encore.
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$id}/messages", ['message' => 'Pardon du délai.'])->assertCreated();
        concernSpace($this->owner)->postJson("/api/v1/member/concerns/{$id}/messages", ['message' => 'Et donc ?'])->assertCreated();
        $this->travel(15)->days();
        $this->artisan('reminders:concerns')->assertSuccessful()->expectsOutputToContain('1 demande(s)');
    });

    it('chaque jour à 07:30, paramétrable ; une erreur est journalisée puis relancée', function () {
        expect(collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'reminders:concerns'))->expression)->toBe('30 7 * * *');

        app()->instance(SendStaleConcernAlerts::class, Mockery::mock(SendStaleConcernAlerts::class, fn ($mock) => $mock->shouldReceive('execute')->andThrow(new RuntimeException('panne'))));
        Log::spy();

        expect(fn () => $this->artisan('reminders:concerns'))->toThrow(RuntimeException::class, 'panne');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Préoccupations'))->once();
    });
});

describe('tableaux des délais (F-PRE-04, RG-PRE-03)', function () {
    beforeEach(function () {
        $this->first = memberConcern($this->owner)->json('data.id');
        $this->travel(2)->days();
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->first}/messages", ['message' => 'Réponse.'])->assertCreated();
        concernSpace($this->manager)->postJson("/api/v1/cga/concerns/{$this->first}/status", ['status' => 'resolved'])->assertOk();
        $this->second = memberConcern($this->owner, ['category' => 'social'])->json('data.id');
        $cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $this->cga->id]);
        memberConcern(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $cabinet->id]), ['category' => 'legal']);
        $this->otherManager = Membership::factory()->role(Role::CgaManager)->create();
        $otherMember = Member::factory()->active()->create(['organization_id' => $this->otherManager->organization_id]);
        memberConcern(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->otherManager->organization_id, 'member_id' => $otherMember->id]));
        concernSpace($this->manager)->postJson('/api/v1/cga/concerns', ['category' => 'legal', 'subject' => 'Au CNCGA', 'message' => 'Question'])->assertCreated();
    });

    it('le gérant voit les chiffres de son centre, les deux portefeuilles, sur une période', function () {
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns/indicators')
            ->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.by_status.resolved', 1)
            ->assertJsonPath('data.by_category.tax', 1)
            ->assertJsonPath('data.by_category.legal', 1)
            ->assertJsonPath('data.by_category.closing_pieces', 0)
            ->assertJsonPath('data.treatment_rate', 0.333)
            ->assertJsonPath('data.responded', 1)
            ->assertJsonPath('data.on_target_rate', 1)
            ->assertJsonPath('data.average_response_hours', 48)
            ->assertJsonPath('data.average_resolution_hours', 48)
            ->assertJsonPath('data.stale', 0)
            ->assertJsonPath('data.stale_days', 15);
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns/indicators?from=2026-11-05&to=2026-11-30')->assertJsonPath('data.count', 2);
        concernSpace($this->manager)->getJson('/api/v1/cga/concerns/indicators?from=2026-11-30&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        concernSpace($this->expert)->getJson('/api/v1/cga/concerns/indicators')->assertForbidden();
    });

    it('RG-PRE-03 · le CNCGA ne voit que des chiffres, du seul portefeuille CGA, par CGA et pour le réseau', function () {
        $response = concernSpace($this->board)->getJson('/api/v1/network/concerns/indicators')
            ->assertOk()
            ->assertJsonPath('data.network.count', 3)
            ->assertJsonPath('data.network.by_category.legal', 0)
            ->assertJsonCount(2, 'data.organizations');
        $rows = collect($response->json('data.organizations'))->keyBy('organization.id');
        expect($rows[$this->cga->id])->count->toBe(2)->treatment_rate->toBe(0.5)
            ->and($rows[$this->otherManager->organization_id]['count'])->toBe(1)
            ->and(json_encode($response->json()))->not->toContain('Patente')->not->toContain('INC SARL')->not->toContain($this->first);

        concernSpace($this->board)->getJson("/api/v1/network/concerns/indicators?organization_id={$this->cga->id}")
            ->assertJsonPath('data.network.count', 2)->assertJsonCount(1, 'data.organizations');
        concernSpace($this->board)->getJson('/api/v1/network/concerns/indicators?organization_id=x')->assertUnprocessable();
        concernSpace($this->manager)->getJson('/api/v1/network/concerns/indicators')->assertForbidden();
        concernSpace(Membership::factory()->role(Role::CncgaDues)->create())->getJson('/api/v1/network/concerns/indicators')->assertForbidden();
    });
});

it('garde un message de préoccupation immuable et ses pièces jointes dans le centre de la demande', function () {
    $id = memberConcern($this->owner, ['attachments' => [concernPdf()]])->json('data.id');
    $message = Tenancy::bypass(static fn (): ConcernMessage => ConcernMessage::query()->with('attachments.file')->where('concern_id', $id)->firstOrFail(), 'Test.');

    expect($message->organization_id)->toBe($this->cga->id)
        ->and($message->attachments->first()->file->organization_id)->toBe($this->cga->id)
        ->and($message->attachments->first()->file->path)->toStartWith("orgs/{$this->cga->id}/concerns/");
    expect(storedConcern($id)->status)->toBe(ConcernStatus::Open);
});
