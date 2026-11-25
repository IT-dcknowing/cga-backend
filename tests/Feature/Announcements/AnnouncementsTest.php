<?php

use App\Domain\Announcements\AnnouncementNotification;
use App\Domain\Announcements\Models\Announcement;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Annonces (06-IMPLEMENTATION-PLAN étape 7.3, PRD M12, F-COM-01, 02) : annonces du CNCGA (tous les CGA, certains
 * CGA, tous les adhérents du réseau) et d'un CGA (ses adhérents, filtrés), plateforme toujours et e-mail en
 * option, fil des annonces reçues, lectures. Décision 0014.
 */

beforeEach(function () {
    Storage::fake();
    config(['queue.default' => 'sync']);
    $this->travelTo('2026-11-04 09:00:00');
    Organization::factory()->cncga()->create();
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create();
    $this->trainingOfficer = Membership::factory()->role(Role::CncgaTraining)->create();
    $this->board = Membership::factory()->role(Role::CncgaViewer)->create();
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $this->cga->id]);
    $this->secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->cga->id]);
    $this->sector = Sector::factory()->create();
    $this->tee = announcementReader($this->cga, ['regime' => 'TEE', 'sector_id' => $this->sector->id]);
    $this->rme = announcementReader($this->cga, ['regime' => 'RME']);
    $this->cabinet = announcementReader($this->cga, ['portfolio' => 'cabinet']);
    $this->otherManager = Membership::factory()->role(Role::CgaManager)->create();
    $this->other = announcementReader($this->otherManager->organization);
});

/**
 * Accès en ligne d'un dossier actif du centre.
 */
function announcementReader(Organization $cga, array $member = []): Membership
{
    $portfolio = $member['portfolio'] ?? 'cga';
    unset($member['portfolio']);
    $factory = Member::factory()->active();
    $record = ($portfolio === 'cabinet' ? $factory->cabinet() : $factory)->create(['organization_id' => $cga->id, ...$member]);

    return Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $cga->id, 'member_id' => $record->id]);
}

function announcementSpace(Membership $membership): TestCase
{
    return inSpace($membership)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

/**
 * Titres des annonces reçues dans cet espace.
 *
 * @return list<string>
 */
function receivedTitles(Membership $membership): array
{
    return collect(announcementSpace($membership)->getJson('/api/v1/me/announcements')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
}

function announcementNotices(Membership $membership): int
{
    return DatabaseNotification::query()->where('notifiable_id', $membership->user_id)->where('type', 'announcement_published')->where('data->membership_id', $membership->id)->count();
}

describe('annonces du CNCGA (F-COM-01)', function () {
    it('à tous les CGA : les agents des CGA actifs la reçoivent, jamais les adhérents ; « publiée à n destinataires »', function () {
        $suspended = Membership::factory()->role(Role::CgaManager)->create();
        Tenancy::bypass(static fn () => Organization::query()->whereKey($suspended->organization_id)->update(['status' => OrganizationStatus::Suspended]), 'Test.');

        announcementSpace($this->president)->post('/api/v1/network/announcements', [
            'title' => 'Assemblée générale', 'body' => 'Le 12 décembre au siège.', 'audience' => 'all_cgas',
            'attachment' => UploadedFile::fake()->createWithContent('convocation.pdf', "%PDF-1.4\n%%EOF"),
        ])->assertCreated()->assertJsonPath('meta.recipients', 4)->assertJsonPath('data.audience', 'all_cgas')->assertJsonPath('data.send_email', false)
            ->assertJsonPath('data.attachment.name', 'convocation.pdf');

        expect(announcementNotices($this->secretary))->toBe(1)
            ->and(announcementNotices($this->otherManager))->toBe(1)
            ->and(announcementNotices($this->tee))->toBe(0)
            ->and(announcementNotices($suspended))->toBe(0)
            ->and(receivedTitles($this->expert))->toBe(['Assemblée générale'])
            ->and(receivedTitles($this->tee))->toBe([]);
        announcementSpace($this->secretary)->getJson('/api/v1/me/announcements')->assertJsonPath('data.0.from.type', 'cncga')
            ->assertJsonPath('data.0.read', false)->assertJsonPath('data.0.attachment.url', fn (?string $url): bool => str_contains((string) $url, 'signature='))
            ->assertJsonMissingPath('data.0.recipients_count')->assertJsonPath('meta.unread', 1);
    });

    it('à certains CGA choisis seulement ; à tous les adhérents du réseau, portefeuille CGA seul', function () {
        announcementSpace($this->trainingOfficer)->postJson('/api/v1/network/announcements', ['title' => 'Aux CGA du Nord', 'body' => 'Texte.', 'audience' => 'selected_cgas', 'organization_ids' => [$this->otherManager->organization_id]])
            ->assertCreated()->assertJsonPath('meta.recipients', 1)->assertJsonPath('data.targets.0.id', $this->otherManager->organization_id);
        announcementSpace($this->president)->postJson('/api/v1/network/announcements', ['title' => 'Aux adhérents', 'body' => 'Texte.', 'audience' => 'all_network_members', 'send_email' => true])
            ->assertCreated()->assertJsonPath('meta.recipients', 3);

        expect(receivedTitles($this->otherManager))->toBe(['Aux CGA du Nord'])
            ->and(receivedTitles($this->manager))->toBe([])
            ->and(receivedTitles($this->tee))->toBe(['Aux adhérents'])
            ->and(receivedTitles($this->other))->toBe(['Aux adhérents'])
            ->and(receivedTitles($this->cabinet))->toBe([]);
    });

    it('refuse une annonce incomplète, une cible inconnue, un CGA choisi absent (422) ; le bureau ne publie pas (403)', function () {
        announcementSpace($this->president)->postJson('/api/v1/network/announcements', ['title' => '', 'body' => ''])->assertUnprocessable()->assertJsonValidationErrors(['title', 'body', 'audience']);
        announcementSpace($this->president)->postJson('/api/v1/network/announcements', ['title' => 'A', 'body' => 'B', 'audience' => 'own_members'])->assertUnprocessable()->assertJsonValidationErrors('audience');
        announcementSpace($this->president)->postJson('/api/v1/network/announcements', ['title' => 'A', 'body' => 'B', 'audience' => 'selected_cgas'])->assertUnprocessable()->assertJsonValidationErrors('organization_ids');
        announcementSpace($this->president)->postJson('/api/v1/network/announcements', ['title' => 'A', 'body' => 'B', 'audience' => 'selected_cgas', 'organization_ids' => [$this->president->organization_id]])->assertUnprocessable();
        announcementSpace($this->board)->postJson('/api/v1/network/announcements', ['title' => 'A', 'body' => 'B', 'audience' => 'all_cgas'])->assertForbidden();
        announcementSpace($this->board)->getJson('/api/v1/network/announcements')->assertForbidden();
        announcementSpace($this->manager)->postJson('/api/v1/network/announcements', ['title' => 'A', 'body' => 'B', 'audience' => 'all_cgas'])->assertForbidden();
        announcementSpace($this->president)->getJson('/api/v1/me/announcements')->assertForbidden();
    });
});

describe('annonces d\'un CGA (F-COM-02)', function () {
    it('à ses adhérents filtrés par régime et secteur : « Message de mon CGA », e-mail en option', function () {
        Notification::fake();
        announcementSpace($this->expert)->postJson('/api/v1/cga/announcements', ['title' => 'Aux TEE', 'body' => 'Rappel TEE.', 'filter' => ['regimes' => ['TEE'], 'sector_ids' => [$this->sector->id]], 'send_email' => true])
            ->assertCreated()->assertJsonPath('meta.recipients', 1)->assertJsonPath('data.audience', 'own_members')
            ->assertJsonPath('data.member_filter', ['portfolio' => 'cga', 'regimes' => ['TEE'], 'sector_ids' => [$this->sector->id]]);
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'À tous', 'body' => 'Fermeture vendredi.'])->assertCreated()->assertJsonPath('meta.recipients', 2);

        Notification::assertSentTo($this->tee->user, fn (AnnouncementNotification $notification, array $channels): bool => $notification->type() === 'announcement_published' && $channels === ['database', 'mail']);
        Notification::assertSentTo($this->tee->user, fn (AnnouncementNotification $notification, array $channels): bool => $channels === ['database']);
        Notification::assertSentTo($this->rme->user, fn (AnnouncementNotification $notification, array $channels): bool => $channels === ['database']);
        Notification::assertNotSentTo($this->rme->user, fn (AnnouncementNotification $notification, array $channels): bool => in_array('mail', $channels, true));
        Notification::assertNotSentTo($this->cabinet->user, AnnouncementNotification::class);
        Notification::assertNotSentTo($this->other->user, AnnouncementNotification::class);
    });

    it('le fil de l\'adhérent suit le filtre ; le portefeuille Cabinet demande members.cabinet.view ; le secrétaire ne publie pas', function () {
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'Aux RME', 'body' => 'Texte.', 'filter' => ['regimes' => ['RME']]])->assertCreated();
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'Au Cabinet', 'body' => 'Texte.', 'filter' => ['portfolio' => 'cabinet']])->assertCreated()->assertJsonPath('meta.recipients', 1);
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'À tous les clients', 'body' => 'Texte.', 'filter' => ['portfolio' => 'all']])->assertCreated()->assertJsonPath('meta.recipients', 3);

        expect(receivedTitles($this->rme))->toBe(['Aux RME', 'À tous les clients'])
            ->and(receivedTitles($this->tee))->toBe(['À tous les clients'])
            ->and(receivedTitles($this->cabinet))->toBe(['Au Cabinet', 'À tous les clients'])
            ->and(receivedTitles($this->other))->toBe([]);
        announcementSpace($this->secretary)->postJson('/api/v1/cga/announcements', ['title' => 'A', 'body' => 'B'])->assertForbidden();
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'A', 'body' => 'B', 'filter' => ['regimes' => ['XXX'], 'sector_ids' => ['inconnu']]])->assertUnprocessable()->assertJsonValidationErrors(['filter.regimes.0', 'filter.sector_ids.0']);
        announcementSpace($this->tee)->postJson('/api/v1/cga/announcements', ['title' => 'A', 'body' => 'B'])->assertForbidden();
    });

    it('liste et détail de l\'auteur, avec destinataires et lectures ; une annonce d\'un autre centre n\'existe pas', function () {
        $id = announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'À tous', 'body' => 'Texte.'])->json('data.id');
        announcementSpace($this->tee)->postJson("/api/v1/me/announcements/{$id}/read")->assertOk()->assertJsonPath('data.read', true);

        announcementSpace($this->expert)->getJson('/api/v1/cga/announcements?filter[audience]=own_members')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipients_count', 2)->assertJsonPath('data.0.reads_count', 1);
        announcementSpace($this->expert)->getJson("/api/v1/cga/announcements/{$id}")->assertOk()->assertJsonPath('data.reads_count', 1);
        announcementSpace($this->otherManager)->getJson("/api/v1/cga/announcements/{$id}")->assertNotFound();
        announcementSpace($this->president)->getJson("/api/v1/network/announcements/{$id}")->assertNotFound();
        announcementSpace($this->secretary)->getJson("/api/v1/cga/announcements/{$id}")->assertForbidden();
        announcementSpace($this->expert)->getJson('/api/v1/cga/announcements?filter[unread]=1')->assertUnprocessable();
    });
});

describe('lectures', function () {
    it('une lecture par utilisateur, la première retenue ; filtre « non lues » ; une annonce non reçue n\'existe pas', function () {
        $id = announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'À tous', 'body' => 'Texte.'])->json('data.id');
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'Deuxième', 'body' => 'Texte.']);

        $first = announcementSpace($this->tee)->postJson("/api/v1/me/announcements/{$id}/read")->assertOk()->json('data.read_at');
        $this->travel(1)->hours();
        announcementSpace($this->tee)->postJson("/api/v1/me/announcements/{$id}/read")->assertOk()->assertJsonPath('data.read_at', $first);
        announcementSpace($this->tee)->getJson('/api/v1/me/announcements?filter[unread]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Deuxième')->assertJsonPath('meta.unread', 1);

        announcementSpace($this->other)->postJson("/api/v1/me/announcements/{$id}/read")->assertNotFound();
        announcementSpace($this->cabinet)->postJson("/api/v1/me/announcements/{$id}/read")->assertNotFound();
        announcementSpace($this->tee)->postJson('/api/v1/me/announcements/pas-un-identifiant/read')->assertNotFound();
        expect(Tenancy::bypass(static fn () => Announcement::query()->findOrFail($id)->reads()->count(), 'Test.'))->toBe(1);
    });

    it('un adhérent radié en consultation seule ne reçoit plus les annonces', function () {
        announcementSpace($this->manager)->postJson('/api/v1/cga/announcements', ['title' => 'À tous', 'body' => 'Texte.'])->assertJsonPath('meta.recipients', 2);
        $this->tee->status = MembershipStatus::ReadOnly;
        $this->tee->save();

        announcementSpace($this->tee)->getJson('/api/v1/me/announcements')->assertForbidden();
        expect(User::query()->whereKey($this->tee->user_id)->exists())->toBeTrue();
    });
});
