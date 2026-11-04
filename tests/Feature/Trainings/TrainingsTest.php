<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Trainings\Actions\PromoteWaitlist;
use App\Domain\Trainings\Actions\SendTrainingReminders;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Formations et séminaires (06-IMPLEMENTATION-PLAN étape 7.2, PRD M11, RG-FOR-01 à 06) : création et
 * publication par le CNCGA ou un CGA, ciblage, inscription, capacité et liste d'attente avec promotion,
 * désinscription jusqu'à J-2, validation, paiement et reçu, présence, attestations, évaluations, rappel J-1.
 * Décision 0013.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $this->travelTo('2026-11-04 09:00:00');
    Organization::factory()->cncga()->profileComplete()->create(['numbering_abbreviation' => 'CNCGA']);
    $this->trainingOfficer = Membership::factory()->role(Role::CncgaTraining)->create();
    $this->board = Membership::factory()->role(Role::CncgaViewer)->create();
    $this->manager = documentAgent();
    $this->cga = $this->manager->organization;
    $this->accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $this->cga->id]);
    $this->billing = Membership::factory()->role(Role::CgaBillingOfficer)->create(['organization_id' => $this->cga->id]);
    $this->member = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'INC SARL', 'regime' => 'TEE']);
    $this->owner = trainee($this->cga, $this->member);
    $this->rmeOwner = trainee($this->cga, Member::factory()->active()->create(['organization_id' => $this->cga->id, 'regime' => 'RME']));
    $this->otherManager = documentAgent();
    $this->otherOwner = trainee($this->otherManager->organization, Member::factory()->active()->create(['organization_id' => $this->otherManager->organization_id]));
});

function trainee(Organization $cga, Member $member): Membership
{
    return Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $cga->id, 'member_id' => $member->id]);
}

/**
 * Requête du SPA dans cet espace, session active à l'heure du test (les tests avancent dans le temps).
 */
function trainingSpace(Membership $membership): TestCase
{
    return inSpace($membership)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

function trainingPayload(array $overrides = []): array
{
    return [
        'title' => 'Tenue de la comptabilité SMT', 'description' => 'Journée pratique.', 'format' => 'in_person', 'location' => 'Abidjan, Plateau',
        'trainer' => 'M. Formateur', 'starts_at' => '2026-11-20T09:00:00+00:00', 'ends_at' => '2026-11-20T17:00:00+00:00', 'capacity' => null,
        'price' => 0, 'requires_approval' => false, 'audiences' => [['type' => 'all_members']], ...$overrides,
    ];
}

function publishedTraining(Membership $organizer, array $overrides = []): string
{
    $space = $organizer->organization->type->value === 'cncga' ? 'network' : 'cga';

    return trainingSpace($organizer)->postJson("/api/v1/{$space}/trainings", [...trainingPayload($overrides), 'publish' => true])->assertCreated()->json('data.id');
}

function selfRegister(Membership $person, string $trainingId)
{
    return trainingSpace($person)->postJson("/api/v1/me/trainings/{$trainingId}/register");
}

function registrationOf(Membership $person, string $trainingId): TrainingRegistration
{
    return Tenancy::bypass(static fn (): TrainingRegistration => TrainingRegistration::query()->where('training_id', $trainingId)->where('membership_id', $person->id)->latest()->firstOrFail(), 'Test.');
}

/**
 * Types des notifications reçues dans cet espace, par ordre alphabétique.
 *
 * @return list<string>
 */
function trainingNotices(Membership $membership): array
{
    $types = $membership->user->notifications()->where('data->membership_id', $membership->id)->pluck('type')->all();
    sort($types);

    return $types;
}

describe('créer et publier (F-FOR-01, RG-FOR-01, RG-FOR-02)', function () {
    it('RG-FOR-01 · le CNCGA publie sans validation croisée : le public ciblé et les CGA concernés sont notifiés', function () {
        $id = trainingSpace($this->trainingOfficer)->postJson('/api/v1/network/trainings', trainingPayload())
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.audiences.0.label', 'Tous les adhérents')->assertJsonPath('meta.notified', null)
            ->json('data.id');
        expect(trainingNotices($this->owner))->toBe([]);

        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('meta.notified', 6)->assertJsonPath('data.can.register', true);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/publish")->assertStatus(409)->assertJsonPath('code', 'training_not_draft');

        expect(trainingNotices($this->owner))->toBe(['training_published'])
            ->and(trainingNotices($this->otherOwner))->toBe(['training_published'])
            ->and(trainingNotices($this->manager))->toBe(['training_published'])
            ->and(trainingNotices($this->accountant))->toBe(['training_published'])
            ->and(trainingNotices($this->otherManager))->toBe(['training_published'])
            ->and(trainingNotices($this->billing))->toBe([]);
        trainingSpace($this->trainingOfficer)->getJson('/api/v1/network/trainings?filter[status]=published&filter[period]=upcoming')->assertOk()->assertJsonCount(1, 'data');
        trainingSpace($this->trainingOfficer)->getJson('/api/v1/network/trainings?filter[period]=past')->assertJsonCount(0, 'data');
    });

    it('refuse une formation incomplète ou mal datée (422) et les espaces sans droit (403)', function () {
        trainingSpace($this->trainingOfficer)->postJson('/api/v1/network/trainings', trainingPayload(['title' => '', 'audiences' => []]))->assertUnprocessable()->assertJsonValidationErrors(['title', 'audiences']);
        trainingSpace($this->trainingOfficer)->postJson('/api/v1/network/trainings', trainingPayload(['ends_at' => '2026-11-19T09:00:00+00:00']))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        trainingSpace($this->trainingOfficer)->postJson('/api/v1/network/trainings', trainingPayload(['starts_at' => '2026-11-01T09:00:00+00:00']))->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        trainingSpace($this->trainingOfficer)->postJson('/api/v1/network/trainings', trainingPayload(['audiences' => [['type' => 'members_by_regime']]]))->assertUnprocessable()->assertJsonValidationErrors('audiences.0.regime');
        trainingSpace($this->trainingOfficer)->postJson('/api/v1/network/trainings', trainingPayload(['online_url' => 'javascript:alert(1)', 'capacity' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['online_url', 'capacity']);

        trainingSpace($this->board)->postJson('/api/v1/network/trainings', trainingPayload())->assertForbidden();
        trainingSpace($this->board)->getJson('/api/v1/network/trainings')->assertForbidden();
        trainingSpace($this->billing)->postJson('/api/v1/cga/trainings', trainingPayload())->assertForbidden();
        trainingSpace($this->manager)->postJson('/api/v1/network/trainings', trainingPayload())->assertForbidden();
        trainingSpace($this->owner)->postJson('/api/v1/cga/trainings', trainingPayload())->assertForbidden();
        $cgaTraining = publishedTraining($this->manager);
        trainingSpace($this->manager)->getJson("/api/v1/network/trainings/{$cgaTraining}")->assertForbidden();
        trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$cgaTraining}")->assertNotFound();
    });

    it('RG-FOR-01 · un CGA ne cible que ses propres agents et adhérents', function () {
        $id = publishedTraining($this->manager, ['audiences' => [['type' => 'all_members'], ['type' => 'cga_staff']]]);

        expect(trainingNotices($this->owner))->toBe(['training_published'])
            ->and(trainingNotices($this->accountant))->toBe(['training_published'])
            ->and(trainingNotices($this->otherOwner))->toBe([])
            ->and(trainingNotices($this->otherManager))->toBe([]);
        trainingSpace($this->otherOwner)->getJson('/api/v1/me/trainings')->assertOk()->assertJsonCount(0, 'data');
        selfRegister($this->otherOwner, $id)->assertNotFound();
        trainingSpace($this->owner)->getJson('/api/v1/me/trainings')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.my_registration', null);
    });

    it('RG-FOR-02 · une formation du CNCGA est vue directement des adhérents concernés : régime, nouveaux adhérents, jamais le Cabinet', function () {
        $newcomer = trainee($this->cga, Member::factory()->active()->create(['organization_id' => $this->cga->id, 'adhesion_date' => '2026-09-01']));
        $cabinet = trainee($this->cga, Member::factory()->active()->cabinet()->create(['organization_id' => $this->cga->id]));
        $tee = publishedTraining($this->trainingOfficer, ['audiences' => [['type' => 'members_by_regime', 'regime' => 'TEE']]]);
        $new = publishedTraining($this->trainingOfficer, ['audiences' => [['type' => 'new_members']]]);
        $all = publishedTraining($this->trainingOfficer);

        $visible = fn (Membership $person): array => collect(trainingSpace($person)->getJson('/api/v1/me/trainings')->assertOk()->json('data'))->pluck('id')->all();
        expect($visible($this->owner))->toEqualCanonicalizing([$tee, $all])
            ->and($visible($this->rmeOwner))->toBe([$all])
            ->and($visible($newcomer))->toEqualCanonicalizing([$tee, $new, $all])
            ->and($visible($cabinet))->toBe([])
            ->and(trainingNotices($cabinet))->toBe([]);
        trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$new}")->assertJsonPath('data.audiences.0.new_member_months', 6);
    });
});

describe('inscriptions (F-FOR-02, RG-FOR-03 à 05)', function () {
    it('RG-FOR-03 · au-delà des places, liste d\'attente ; un désistement promeut la première personne, notifiée', function () {
        $id = publishedTraining($this->trainingOfficer, ['capacity' => 1]);
        selfRegister($this->owner, $id)->assertCreated()->assertJsonPath('data.status', 'registered')->assertJsonPath('data.training.id', $id);
        selfRegister($this->rmeOwner, $id)->assertCreated()->assertJsonPath('data.status', 'waitlisted')->assertJsonPath('data.waitlist_rank', 1);
        selfRegister($this->otherOwner, $id)->assertCreated()->assertJsonPath('data.waitlist_rank', 2);
        selfRegister($this->owner, $id)->assertStatus(409)->assertJsonPath('code', 'training_already_registered');
        trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$id}")->assertJsonPath('data.seats', ['registered' => 1, 'waitlisted' => 2, 'pending' => 0, 'left' => 0]);

        $mine = registrationOf($this->owner, $id);
        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$mine->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect(registrationOf($this->rmeOwner, $id))->status->toBe(RegistrationStatus::Registered)->promoted_at->not->toBeNull()
            ->and(registrationOf($this->otherOwner, $id)->status)->toBe(RegistrationStatus::Waitlisted)
            ->and(trainingNotices($this->rmeOwner))->toBe(['training_published', 'training_registration', 'training_registration']);
        trainingSpace($this->otherOwner)->getJson('/api/v1/me/training-registrations')->assertJsonPath('data.0.waitlist_rank', 1);
        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$mine->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'training_registration_not_active');
        selfRegister($this->owner, $id)->assertCreated()->assertJsonPath('data.status', 'waitlisted');
    });

    it('RG-FOR-03 · plus de places : la liste d\'attente est promue ; trainings:waitlist chaque quart d\'heure', function () {
        $id = publishedTraining($this->trainingOfficer, ['capacity' => 1]);
        selfRegister($this->owner, $id)->assertCreated();
        selfRegister($this->rmeOwner, $id)->assertCreated();
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$id}", trainingPayload(['capacity' => 0]))->assertUnprocessable();

        Tenancy::bypass(static fn () => Training::query()->whereKey($id)->update(['capacity' => 2]), 'Test.');
        $this->artisan('trainings:waitlist')->assertSuccessful()->expectsOutputToContain('1 personne(s) promue(s)');
        expect(registrationOf($this->rmeOwner, $id)->status)->toBe(RegistrationStatus::Registered)
            ->and(collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'trainings:waitlist'))->expression)->toBe('*/15 * * * *');

        selfRegister($this->otherOwner, $id)->assertJsonPath('data.status', 'waitlisted');
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$id}", trainingPayload(['capacity' => 3]))->assertOk()->assertJsonPath('data.seats.registered', 3);
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$id}", trainingPayload(['capacity' => 2]))->assertStatus(409)->assertJsonPath('code', 'training_capacity_below_registered');
    });

    it('RG-FOR-04 · désinscription possible jusqu\'à J-2, paramétrable', function () {
        $id = publishedTraining($this->trainingOfficer);
        $registration = selfRegister($this->owner, $id)->json('data.id');
        $this->travelTo('2026-11-18 10:00:00');

        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$registration}/cancel")->assertStatus(409)->assertJsonPath('code', 'training_cancellation_closed');
        app(SettingsService::class)->set('trainings.cancellation_days', 1);
        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$registration}/cancel")->assertOk();
    });

    it('RG-FOR-05 · avec validation : en attente, puis validée ou refusée par l\'organisateur', function () {
        $id = publishedTraining($this->trainingOfficer, ['requires_approval' => true, 'capacity' => 1]);
        $first = selfRegister($this->owner, $id)->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $second = selfRegister($this->rmeOwner, $id)->json('data.id');
        $third = selfRegister($this->otherOwner, $id)->json('data.id');

        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$first}/approve")->assertOk()->assertJsonPath('data.status', 'registered')->assertJsonPath('data.person.company_name', null);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$second}/approve")->assertOk()->assertJsonPath('data.status', 'waitlisted');
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$third}/reject", ['reason' => 'Public non concerné'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Public non concerné');
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$first}/approve")->assertStatus(409)->assertJsonPath('code', 'training_registration_not_pending');
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/inconnue/approve")->assertNotFound();
        selfRegister($this->otherOwner, $id)->assertStatus(409)->assertJsonPath('code', 'training_already_registered');

        expect(trainingNotices($this->owner))->toBe(['training_published', 'training_registration', 'training_registration']);
        trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$id}/registrations?status=waitlisted")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.person.kind', 'member');
        trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$id}/registrations?status=autre")->assertUnprocessable();
    });

    it('le CGA inscrit ses agents et adhérents ciblés à une formation du réseau, et les désinscrit', function () {
        $id = publishedTraining($this->trainingOfficer, ['audiences' => [['type' => 'all_members'], ['type' => 'cga_accountants']], 'capacity' => 1]);

        trainingSpace($this->manager)->getJson('/api/v1/cga/network-trainings')->assertOk()->assertJsonPath('data.0.id', $id);
        trainingSpace($this->manager)->getJson("/api/v1/cga/network-trainings/{$id}")->assertOk()->assertJsonPath('data.organizer.type', 'cncga');
        $candidates = collect(trainingSpace($this->manager)->getJson("/api/v1/cga/network-trainings/{$id}/candidates")->assertOk()->json('data'));
        expect($candidates->pluck('membership_id')->all())->toEqualCanonicalizing([$this->owner->id, $this->rmeOwner->id, $this->accountant->id])
            ->and($candidates->firstWhere('membership_id', $this->owner->id)['company_name'])->toBe('INC SARL');
        trainingSpace($this->manager)->getJson("/api/v1/cga/network-trainings/{$id}/candidates?search=".urlencode($this->accountant->user->last_name))->assertJsonCount(1, 'data');

        trainingSpace($this->manager)->postJson("/api/v1/cga/network-trainings/{$id}/registrations", ['membership_ids' => [$this->owner->id, $this->accountant->id]])
            ->assertCreated()->assertJsonPath('meta.registrations', ['registered' => 1, 'waitlisted' => 1, 'pending' => 0, 'already' => 0]);
        trainingSpace($this->manager)->postJson("/api/v1/cga/network-trainings/{$id}/registrations", ['membership_ids' => [$this->billing->id]])->assertStatus(409)->assertJsonPath('code', 'training_not_targeted');
        trainingSpace($this->manager)->postJson("/api/v1/cga/network-trainings/{$id}/registrations", ['membership_ids' => [$this->otherOwner->id]])->assertUnprocessable()->assertJsonValidationErrors('membership_ids');
        trainingSpace($this->billing)->getJson('/api/v1/cga/network-trainings')->assertForbidden();
        selfRegister($this->otherOwner, $id)->assertCreated();

        $registrations = trainingSpace($this->manager)->getJson("/api/v1/cga/network-trainings/{$id}/registrations")->assertOk()->assertJsonCount(2, 'data');
        $owner = collect($registrations->json('data'))->firstWhere('person.membership_id', $this->owner->id)['id'];
        trainingSpace($this->manager)->postJson("/api/v1/cga/training-registrations/{$owner}/cancel")->assertOk();
        expect(trainingNotices($this->owner))->toBe(['training_published', 'training_registration', 'training_registration'])
            ->and(registrationOf($this->accountant, $id)->status)->toBe(RegistrationStatus::Registered);
        trainingSpace($this->billing)->postJson("/api/v1/cga/training-registrations/{$owner}/cancel")->assertForbidden();
        trainingSpace($this->otherManager)->postJson("/api/v1/cga/training-registrations/{$owner}/cancel")->assertNotFound();
    });

    it('RG-SEC-01 · le CNCGA n\'inscrit que des agents et ne voit jamais l\'entreprise d\'un adhérent', function () {
        $id = publishedTraining($this->trainingOfficer, ['audiences' => [['type' => 'all_members'], ['type' => 'cga_managers']]]);
        selfRegister($this->owner, $id)->assertCreated();

        $candidates = collect(trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$id}/candidates")->assertOk()->json('data'));
        expect($candidates->pluck('kind')->unique()->all())->toBe(['agent'])
            ->and($candidates->pluck('membership_id')->all())->toContain($this->manager->id, $this->otherManager->id);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations", ['membership_ids' => [$this->owner->id]])->assertUnprocessable();
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations", ['membership_ids' => [$this->manager->id]])->assertCreated()->assertJsonPath('meta.registrations.registered', 1);

        $list = trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$id}/registrations")->assertOk()->assertJsonCount(2, 'data');
        expect(json_encode($list->json()))->not->toContain('INC SARL');
        trainingSpace($this->owner)->getJson('/api/v1/me/training-registrations')->assertJsonPath('data.0.person.company_name', 'INC SARL');
    });

    it('un agent ciblé s\'inscrit lui-même ; un accès en consultation seule ne s\'inscrit pas', function () {
        $id = publishedTraining($this->trainingOfficer, ['audiences' => [['type' => 'cga_staff']]]);
        selfRegister($this->billing, $id)->assertCreated()->assertJsonPath('data.person.kind', 'agent');
        selfRegister($this->owner, $id)->assertNotFound();

        $this->rmeOwner->status = MembershipStatus::ReadOnly;
        $this->rmeOwner->save();
        trainingSpace($this->rmeOwner)->getJson('/api/v1/me/trainings')->assertForbidden();
        trainingSpace($this->trainingOfficer)->getJson('/api/v1/me/trainings')->assertForbidden();
        selfRegister($this->billing, 'inconnue')->assertNotFound();
    });
});

describe('paiement et reçu (RG-FOR-06)', function () {
    it('RG-FOR-06 · à payer, puis payé avec un reçu numéroté de l\'organisateur', function () {
        $id = publishedTraining($this->trainingOfficer, ['price' => 25_000]);
        $registration = selfRegister($this->owner, $id)->assertJsonPath('data.payment_status', 'to_pay')->json('data.id');

        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$registration}/payment", ['paid_on' => '2026-11-05', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$registration}/payment", ['paid_on' => '2026-11-04', 'method' => 'cash', 'reference' => 'ESP-12'])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.receipt_number', 'FOR-CNCGA-2026-00001')
            ->assertJsonPath('data.receipt_url', fn (?string $url): bool => str_contains((string) $url, 'signature='));
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$id}/registrations/{$registration}/payment", ['paid_on' => '2026-11-04', 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'training_payment_not_due');

        $receipt = Tenancy::bypass(static fn () => GeneratedDocument::query()->where('type', 'training_receipt')->sole(), 'Test.');
        expect($receipt)->status->toBe(DocumentStatus::Valid)->number->toBe('FOR-CNCGA-2026-00001')->visible_to_member->toBeFalse();
        trainingSpace($this->owner)->getJson('/api/v1/me/training-registrations')->assertJsonPath('data.0.receipt_number', 'FOR-CNCGA-2026-00001');
    });

    it('RG-FOR-06 · une formation gratuite n\'attend aucun paiement ; un changement de prix met à jour les inscriptions non réglées', function () {
        $id = publishedTraining($this->manager);
        $registration = selfRegister($this->owner, $id)->assertJsonPath('data.payment_status', 'not_applicable')->json('data.id');
        trainingSpace($this->manager)->postJson("/api/v1/cga/trainings/{$id}/registrations/{$registration}/payment", ['paid_on' => '2026-11-04', 'method' => 'cash'])->assertStatus(409);

        trainingSpace($this->manager)->putJson("/api/v1/cga/trainings/{$id}", trainingPayload(['price' => 10_000]))->assertOk()->assertJsonPath('data.price', 10_000);
        expect(registrationOf($this->owner, $id)->payment_status->value)->toBe('to_pay');
        trainingSpace($this->manager)->getJson("/api/v1/cga/trainings/{$id}/registrations")->assertJsonPath('data.0.person.company_name', 'INC SARL');
    });
});

describe('après la session (F-FOR-04)', function () {
    beforeEach(function () {
        $this->id = publishedTraining($this->trainingOfficer);
        $this->present = selfRegister($this->owner, $this->id)->json('data.id');
        $this->absent = selfRegister($this->rmeOwner, $this->id)->json('data.id');
    });

    it('présence à partir du début, clôture une fois chacun émargé, attestation pour les présents', function () {
        $marks = ['attendance' => [['registration_id' => $this->present, 'status' => 'present'], ['registration_id' => $this->absent, 'status' => 'absent']]];
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$this->id}/attendance", $marks)->assertStatus(409)->assertJsonPath('code', 'training_not_in_session');
        $this->travelTo('2026-11-20 18:00:00');

        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$this->id}/attendance", ['attendance' => [['registration_id' => $this->present, 'status' => 'present']]])->assertOk();
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$this->id}/complete")->assertStatus(409)->assertJsonPath('code', 'training_attendance_incomplete');
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$this->id}/attendance", ['attendance' => [['registration_id' => $this->present, 'status' => 'peut-être']]])->assertUnprocessable();
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$this->id}/attendance", $marks)->assertOk()->assertJsonPath('data.marked', 2);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$this->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('meta.certificates', 1);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$this->id}/complete")->assertStatus(409);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$this->id}/cancel", ['reason' => 'Trop tard'])->assertStatus(409)->assertJsonPath('code', 'training_not_cancellable');
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$this->id}", trainingPayload(['starts_at' => '2026-12-01T09:00:00+00:00', 'ends_at' => '2026-12-01T10:00:00+00:00']))->assertStatus(409)->assertJsonPath('code', 'training_locked');

        $certificate = Tenancy::bypass(static fn () => GeneratedDocument::query()->where('type', 'training_certificate')->sole(), 'Test.');
        expect($certificate->status)->toBe(DocumentStatus::Valid)
            ->and(trainingNotices($this->owner))->toBe(['training_published', 'training_registration', 'training_registration']);
        trainingSpace($this->owner)->getJson('/api/v1/me/training-registrations')->assertJsonPath('data.0.attendance', 'present')
            ->assertJsonPath('data.0.certificate_url', fn (?string $url): bool => str_contains((string) $url, 'signature='));
        trainingSpace($this->rmeOwner)->getJson('/api/v1/me/training-registrations')->assertJsonPath('data.0.certificate_url', null);
    });

    it('évaluation à chaud des présents, résultats agrégés sans nom', function () {
        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$this->present}/evaluation", ['score' => 5])->assertStatus(409)->assertJsonPath('code', 'training_evaluation_closed');
        $this->travelTo('2026-11-20 18:00:00');
        trainingSpace($this->trainingOfficer)->putJson("/api/v1/network/trainings/{$this->id}/attendance", ['attendance' => [['registration_id' => $this->present, 'status' => 'present'], ['registration_id' => $this->absent, 'status' => 'absent']]]);
        trainingSpace($this->trainingOfficer)->postJson("/api/v1/network/trainings/{$this->id}/complete")->assertOk();

        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$this->present}/evaluation", ['score' => 6])->assertUnprocessable()->assertJsonValidationErrors('score');
        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$this->present}/evaluation", ['score' => 4, 'comment' => 'Très concret.'])
            ->assertOk()->assertJsonPath('data.evaluation', ['score' => 4, 'comment' => 'Très concret.']);
        trainingSpace($this->rmeOwner)->postJson("/api/v1/me/training-registrations/{$this->absent}/evaluation", ['score' => 1])->assertStatus(409);
        trainingSpace($this->rmeOwner)->postJson("/api/v1/me/training-registrations/{$this->present}/evaluation", ['score' => 1])->assertForbidden();
        trainingSpace($this->owner)->postJson("/api/v1/me/training-registrations/{$this->present}/cancel")->assertStatus(409);

        $summary = trainingSpace($this->trainingOfficer)->getJson("/api/v1/network/trainings/{$this->id}/evaluations")
            ->assertOk()->assertJsonPath('data.present', 1)->assertJsonPath('data.answers', 1)->assertJsonPath('data.average', 4)
            ->assertJsonPath('data.distribution.4', 1)->assertJsonPath('data.comments.0.comment', 'Très concret.');
        expect(json_encode($summary->json()))->not->toContain($this->owner->user->last_name);
    });
});

describe('annulation et rappels', function () {
    it('« Annuler la formation » avec un motif : les inscrits sont notifiés, les inscriptions se ferment', function () {
        $id = publishedTraining($this->manager);
        selfRegister($this->owner, $id)->assertCreated();

        trainingSpace($this->manager)->postJson("/api/v1/cga/trainings/{$id}/cancel", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        trainingSpace($this->manager)->postJson("/api/v1/cga/trainings/{$id}/cancel", ['reason' => 'Formateur indisponible'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Formateur indisponible')->assertJsonPath('data.can.register', false);

        expect(trainingNotices($this->owner))->toBe(['training_published', 'training_registration', 'training_registration']);
        selfRegister($this->rmeOwner, $id)->assertStatus(409)->assertJsonPath('code', 'training_registration_closed');
        trainingSpace($this->manager)->postJson("/api/v1/cga/trainings/{$id}/registrations", ['membership_ids' => [$this->rmeOwner->id]])->assertStatus(409)->assertJsonPath('code', 'training_registration_closed');
    });

    it('rappel aux inscrits la veille de la session, une seule fois ; reminders:trainings chaque jour à 07:40', function () {
        $id = publishedTraining($this->trainingOfficer);
        selfRegister($this->owner, $id)->assertCreated();

        $this->artisan('reminders:trainings')->assertSuccessful()->expectsOutputToContain('0 rappel(s)');
        $this->travelTo('2026-11-19 07:40:00');
        $this->artisan('reminders:trainings')->assertSuccessful()->expectsOutputToContain('1 rappel(s)');
        $this->artisan('reminders:trainings')->assertSuccessful()->expectsOutputToContain('0 rappel(s)');

        expect($this->owner->user->notifications()->where('type', 'training_registration')->latest()->first()->data['body'])->toContain('rappel, la session commence le')
            ->and(collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'reminders:trainings'))->expression)->toBe('40 7 * * *');
    });

    it('journalise puis relance une erreur des tâches des formations', function (string $command, string $action, string $method) {
        app()->instance($action, Mockery::mock($action, fn ($mock) => $mock->shouldReceive($method)->andThrow(new RuntimeException('panne'))));
        Log::spy();

        expect(fn () => $this->artisan($command))->toThrow(RuntimeException::class, 'panne');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Formations'))->once();
    })->with([
        ['trainings:waitlist', PromoteWaitlist::class, 'all'],
        ['reminders:trainings', SendTrainingReminders::class, 'execute'],
    ]);
});
