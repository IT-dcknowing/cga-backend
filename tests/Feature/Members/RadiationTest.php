<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Support\AuthSession;
use App\Domain\Members\Actions\CreateExpectedPieces;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Events\RadiationCancelled;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Members\Models\Radiation;
use App\Domain\Members\Support\MembershipExclusivity;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\UserPermissionOverride;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Radiation en deux temps (06-IMPLEMENTATION-PLAN étape 2.4, F-ADH-06, F-ADH-07) : demande, validation
 * (clore ou basculer dans le portefeuille Cabinet), annulation par le gérant, accès en consultation
 * seule (RG-ADH-10 à 13, RG-ADH-17, RG-PTF-04).
 */

beforeEach(function () {
    Storage::fake();
});

/**
 * Espace de l'agent, actif à l'instant (le délai d'inactivité de la session suit les voyages dans le temps).
 */
function radiationSpace(Membership $agent): TestCase
{
    return inSpace($agent)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

function radiationManager(): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create([
        'organization_id' => Organization::factory()->create(['numbering_abbreviation' => 'DCK', 'abbreviation_locked_at' => now()])->id,
    ]);
}

function radiationAgent(Membership $manager, Role $role): Membership
{
    return Membership::factory()->role($role)->create(['organization_id' => $manager->organization_id]);
}

function radiationMember(Membership $manager, array $overrides = []): Member
{
    return Member::factory()->active()->create(['organization_id' => $manager->organization_id, ...$overrides]);
}

function radiationProof(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('piece.pdf', "%PDF-1.4\n%%EOF");
}

function requestRadiation(Membership $agent, Member $member, array $overrides = []): TestResponse
{
    return radiationSpace($agent)->post("/api/v1/cga/members/{$member->id}/radiation", [
        'initiator' => 'cga',
        'reason' => 'reclassified_real_regime',
        'effective_date' => '2026-09-30',
        'observation' => 'Reclassement au RSI notifié le 15/09/2026.',
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

function validateRadiation(Membership $agent, Member $member, string $outcome = 'closed', ?string $reference = 'SP-2026-114'): TestResponse
{
    return radiationSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/radiation/validate", ['outcome' => $outcome, 'validation_reference' => $reference]);
}

function cancelRadiation(Membership $agent, Member $member, ?string $reason = 'Radiation saisie par erreur.'): TestResponse
{
    return radiationSpace($agent)->postJson("/api/v1/cga/members/{$member->id}/radiation/cancel", ['reason' => $reason]);
}

function memberAccess(Member $member, bool $invited = false): Membership
{
    $factory = Membership::factory()->role(Role::MemberOwner);

    return ($invited ? $factory->invited() : $factory)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]);
}

describe('demande (RG-ADH-17, premier temps)', function () {
    it('RG-ADH-17 enregistre la demande : radiation en cours, dossier suivi, exclusivité toujours active', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);

        requestRadiation($manager, $member, ['sp_request_reference' => 'DEM-2026-07'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'radiation_pending')
            ->assertJsonPath('data.radiation.status', 'requested')
            ->assertJsonPath('data.radiation.initiator', 'cga')
            ->assertJsonPath('data.radiation.reason_label', 'Reclassement au régime réel')
            ->assertJsonPath('data.radiation.effective_date', '2026-09-30')
            ->assertJsonPath('data.radiation.sp_request_reference', 'DEM-2026-07')
            ->assertJsonPath('data.radiation.observation', 'Reclassement au RSI notifié le 15/09/2026.')
            ->assertJsonPath('data.radiation.cancellable_until', null)
            ->assertJsonPath('data.radiation.can.validate', true)
            ->assertJsonPath('data.radiation.can.cancel', true)
            ->assertJsonPath('data.can.request_radiation', false);

        expect(app(MembershipExclusivity::class)->enrolledElsewhere($member->ncc, Organization::factory()->create()->id))->toBeTrue()
            ->and(AuditLog::query()->where('event', 'radiation_requested')->where('subject_id', $member->id)->exists())->toBeTrue();
    });

    it('contrôle le motif de l\'initiateur, la pièce exigée, la référence SP-CGA et la date d\'effet (config/radiations.php)', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);

        requestRadiation($manager, $member, ['initiator' => 'member', 'reason' => 'zone_change'])->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Ce motif n\'est pas prévu pour une radiation à l\'initiative de : Contribuable.');
        requestRadiation($manager, $member, ['reason' => 'serious_breach'])->assertUnprocessable()
            ->assertJsonPath('errors.proof.0', 'Joignez la pièce exigée pour ce motif : procès-verbal de la commission.');
        requestRadiation($manager, $member, ['initiator' => 'member', 'reason' => 'dissatisfaction', 'proof' => radiationProof(), 'sp_request_reference' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('sp_request_reference')->assertJsonMissingValidationErrors('proof');
        requestRadiation($manager, $member, ['reason' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('reason_other');
        requestRadiation($manager, $member, ['reason_other' => 'Précision'])->assertUnprocessable()->assertJsonValidationErrors('reason_other');
        requestRadiation($manager, $member, ['effective_date' => '2026-02-01'])->assertUnprocessable()
            ->assertJsonPath('errors.effective_date.0', 'La date d\'effet ne peut pas précéder la date d\'adhésion (01/03/2026).');
        requestRadiation($manager, $member, ['initiator' => 'x', 'observation' => ''])->assertUnprocessable()->assertJsonValidationErrors(['initiator', 'observation']);
        expect($member->fresh()?->status)->toBe(MemberStatus::Active);

        requestRadiation($manager, $member, ['reason' => 'serious_breach', 'proof' => radiationProof(), 'observation' => 'Manquements constatés par la commission.'])
            ->assertCreated()
            ->assertJsonPath('data.radiation.proof.name', 'piece.pdf');
        expect(DB::table('radiations')->where('member_id', $member->id)->value('observation'))->not->toContain('Manquements');
    });

    it('refuse une seconde demande, un brouillon et un dossier du portefeuille Cabinet', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);
        requestRadiation($manager, $member)->assertCreated();

        requestRadiation($manager, $member)->assertStatus(409)->assertJsonPath('code', 'radiation_already_requested');
        requestRadiation($manager, Member::factory()->create(['organization_id' => $manager->organization_id]))->assertStatus(409)->assertJsonPath('code', 'member_not_active');
        requestRadiation($manager, radiationMember($manager, ['portfolio' => 'cabinet', 'membership_number' => null]))->assertStatus(409)->assertJsonPath('code', 'radiation_cga_only');
    });

    it('RG-ADH-17 valide dans la même opération une radiation du contribuable ou pour décès, jamais les autres', function () {
        $manager = radiationManager();

        requestRadiation($manager, radiationMember($manager), ['initiator' => 'member', 'reason' => 'dissatisfaction', 'proof' => radiationProof(), 'validate_now' => '1', 'outcome' => 'closed'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'radiated')
            ->assertJsonPath('data.radiation.status', 'validated');
        requestRadiation($manager, radiationMember($manager), ['reason' => 'death', 'validate_now' => '1', 'outcome' => 'closed'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'radiated');

        $member = radiationMember($manager);
        requestRadiation($manager, $member, ['validate_now' => '1', 'outcome' => 'closed'])->assertUnprocessable()
            ->assertJsonPath('errors.validate_now.0', 'Seule une radiation à l\'initiative du contribuable ou consécutive à un décès se valide dans la même opération.');
        requestRadiation($manager, $member, ['reason' => 'death', 'validate_now' => '1'])->assertUnprocessable()->assertJsonValidationErrors('outcome');
        expect($member->fresh()?->status)->toBe(MemberStatus::Active);
    });

    it('donne la table des motifs par initiateur, avec la pièce exigée et la validation immédiate', function () {
        $manager = radiationManager();

        $data = radiationSpace($manager)->getJson('/api/v1/cga/members/radiation-options')->assertOk()->json('data');

        $initiators = collect($data['initiators'])->keyBy('value');
        expect($initiators->keys()->all())->toBe(['cga', 'member', 'sp_cga'])
            ->and($initiators['cga']['sp_request_reference'])->toBeTrue()
            ->and(collect($initiators['cga']['reasons'])->pluck('value')->all())->toBe(['zone_change', 'follow_up_impossible', 'serious_breach', 'reclassified_real_regime', 'cessation', 'business_sale', 'death', 'other'])
            ->and(collect($initiators['cga']['reasons'])->firstWhere('value', 'death')['immediate'])->toBeTrue()
            ->and(collect($initiators['member']['reasons'])->every(fn (array $reason): bool => $reason['immediate'] && $reason['proof']['code'] === 'termination_letter'))->toBeTrue()
            ->and($initiators['sp_cga']['reasons'][0]['proof']['label'])->toBe('décision de retrait d\'agrément')
            ->and(collect($data['outcomes'])->pluck('value')->all())->toBe(['closed', 'moved_to_cabinet']);
        radiationSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/cga/members/radiation-options')->assertForbidden();
    });
});

describe('validation (RG-ADH-17, second temps)', function () {
    it('RG-ADH-10 clore : dossier radié, état des résiliations du trimestre, contrat terminé, exclusivité levée', function () {
        Event::fake([RadiationValidated::class]);
        $manager = radiationManager();
        $member = radiationMember($manager);
        requestRadiation($manager, $member)->assertCreated();

        $radiation = validateRadiation($manager, $member)
            ->assertOk()
            ->assertJsonPath('data.status', 'radiated')
            ->assertJsonPath('data.contract.ended_on', '2026-09-30')
            ->assertJsonPath('data.radiation.status', 'validated')
            ->assertJsonPath('data.radiation.outcome', 'closed')
            ->assertJsonPath('data.radiation.validation_reference', 'SP-2026-114')
            ->assertJsonPath('data.radiation.report_year', 2026)
            ->assertJsonPath('data.radiation.report_quarter', 3)
            ->assertJsonPath('data.radiation.can.validate', false)
            ->assertJsonPath('data.radiation.can.cancel', true)
            ->json('data.radiation');

        expect(substr((string) $radiation['cancellable_until'], 0, 10))->toBe(today()->addDays(30)->toDateString())
            ->and(app(MembershipExclusivity::class)->enrolledElsewhere($member->ncc, Organization::factory()->create()->id))->toBeFalse()
            ->and(AuditLog::query()->where('event', 'radiation_validated')->where('subject_id', $member->id)->value('context'))
            ->toEqual(['radiation_id' => $radiation['id'], 'outcome' => 'closed', 'reference' => 'SP-2026-114']);
        Event::assertDispatched(RadiationValidated::class, fn (RadiationValidated $event): bool => $event->radiation->id === $radiation['id']);
    });

    it('RG-ADH-12 passe les accès de l\'adhérent radié en consultation seule pour 6 mois, puis la tâche quotidienne les ferme', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);
        $owner = memberAccess($member);
        $invited = memberAccess($member, invited: true);
        requestRadiation($manager, $member)->assertCreated();
        validateRadiation($manager, $member)->assertOk();

        foreach ([$owner, $invited] as $access) {
            expect($access->fresh()?->status)->toBe(MembershipStatus::ReadOnly)
                ->and($access->fresh()?->read_only_until?->toDateString())->toBe(today()->addMonths(6)->toDateString());
        }

        $this->artisan('members:radiation-expiry')->expectsOutput('Aucun accès à fermer.')->assertSuccessful();
        $this->travelTo(today()->addMonths(6)->addDay());
        $this->artisan('members:radiation-expiry')->expectsOutput('2 accès fermé(s).')->assertSuccessful();

        expect($owner->fresh()?->status)->toBe(MembershipStatus::Disabled)
            ->and($member->fresh()?->status)->toBe(MemberStatus::Radiated);
    });

    it('RG-ADH-12 suit la durée de consultation seule choisie par le CGA', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);
        $owner = memberAccess($member);
        app(SettingsService::class)->set('members.read_only_months', 12, $manager->organization_id);

        requestRadiation($manager, $member, ['reason' => 'death', 'validate_now' => '1', 'outcome' => 'closed'])->assertCreated();

        expect($owner->fresh()?->read_only_until?->toDateString())->toBe(today()->addMonths(12)->toDateString());
    });

    it('RG-PTF-04 bascule dans le portefeuille Cabinet : n° client, fin du rattachement à l\'apporteur, contrat sans objet, bascule historisée', function () {
        $manager = radiationManager();
        $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
        $member = radiationMember($manager, ['referrer_id' => $referrer->id]);
        $owner = memberAccess($member);
        app(TenantContext::class)->runAs($manager, fn () => app(CreateExpectedPieces::class)->execute($member));
        requestRadiation($manager, $member)->assertCreated();

        $response = validateRadiation(radiationAgent($manager, Role::CgaAccountantExpert), $member, 'moved_to_cabinet')
            ->assertOk()
            ->assertJsonPath('data.portfolio', 'cabinet')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.client_number', 'CAB-DCK-00001')
            ->assertJsonPath('data.membership_number', $member->membership_number)
            ->assertJsonPath('data.contract.ended_on', '2026-09-30')
            ->assertJsonPath('data.radiation.outcome', 'moved_to_cabinet');

        $movement = app(TenantContext::class)->runAs($manager, fn () => PortfolioMovement::query()->where('member_id', $member->id)->sole());
        $contract = app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->where('piece_type', PieceType::SignedCgaContract)->sole());
        expect($movement->direction->value)->toBe('cga_to_cabinet')
            ->and($movement->radiation_id)->toBe($response->json('data.radiation.id'))
            ->and($movement->previous_referrer_id)->toBe($referrer->id)
            ->and($movement->reason)->toBe('Reclassement au régime réel')
            ->and($member->fresh()?->referrer_id)->toBeNull()
            ->and($contract->status)->toBe(PieceStatus::NotApplicable)
            ->and($owner->fresh()?->status)->toBe(MembershipStatus::Active)
            ->and(app(MembershipExclusivity::class)->enrolledElsewhere($member->ncc, Organization::factory()->create()->id))->toBeFalse();
    });

    it('refuse la validation sans demande en cours, sans le droit, et la bascule sans le droit de basculer', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);
        $accountant = radiationAgent($manager, Role::CgaAccountant);
        $expert = radiationAgent($manager, Role::CgaAccountantExpert);

        validateRadiation($manager, $member)->assertStatus(409)->assertJsonPath('code', 'radiation_not_requested');
        requestRadiation($accountant, $member)->assertForbidden();
        requestRadiation($manager, $member)->assertCreated();
        validateRadiation($accountant, $member)->assertForbidden();
        validateRadiation($manager, $member, 'ailleurs')->assertUnprocessable()->assertJsonValidationErrors('outcome');

        app(TenantContext::class)->runAs($expert, function () use ($expert, $manager): void {
            $override = new UserPermissionOverride;
            $override->membership_id = $expert->id;
            $override->permission = 'members.portfolio.move';
            $override->granted = false;
            $override->updated_by = $manager->user_id;
            $override->save();
        });
        validateRadiation($expert, $member, 'moved_to_cabinet')->assertForbidden();
        validateRadiation($expert, $member)->assertOk();
        validateRadiation($manager, Member::factory()->active()->create())->assertNotFound();
    });

    it('RG-ADH-13 un retour est une nouvelle adhésion : nouveau dossier, l\'ancien reste consultable, retrouvé par le NCC', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);
        requestRadiation($manager, $member, ['reason' => 'death', 'validate_now' => '1', 'outcome' => 'closed'])->assertCreated();

        $form = [
            'portfolio' => 'cga', 'entity_type' => 'company', 'company_name' => 'Retour du contribuable', 'legal_form' => 'SARL',
            'ncc' => $member->ncc, 'regime' => 'TEE', 'main_activity' => 'Commerce', 'phone' => '0707070707',
        ];
        $return = radiationSpace($manager)->postJson('/api/v1/cga/members', $form)->assertCreated()->assertJsonPath('data.membership_number', null)->json('data.id');

        expect($return)->not->toBe($member->id);
        radiationSpace($manager)->getJson("/api/v1/cga/members/{$member->id}")->assertOk()->assertJsonPath('data.status', 'radiated');
        radiationSpace($manager)->getJson('/api/v1/cga/members?filter[search]='.$member->ncc)->assertOk()->assertJsonCount(2, 'data');
        radiationSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", [...$form, 'company_name' => 'Autre nom'])->assertStatus(409)->assertJsonPath('code', 'member_closed');
    });
});

describe('annulation (F-ADH-07, RG-ADH-11)', function () {
    it('RG-ADH-11 le gérant annule une radiation validée : dossier, contrat, accès et exclusivité restaurés', function () {
        Event::fake([RadiationCancelled::class]);
        $manager = radiationManager();
        $member = radiationMember($manager);
        $owner = memberAccess($member);
        $invited = memberAccess($member, invited: true);
        requestRadiation($manager, $member)->assertCreated();
        $radiationId = validateRadiation($manager, $member)->json('data.radiation.id');

        cancelRadiation($manager, $member)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.contract.ended_on', null)
            ->assertJsonPath('data.radiation', null)
            ->assertJsonPath('data.can.request_radiation', true);

        $radiation = app(TenantContext::class)->runAs($manager, fn () => Radiation::query()->findOrFail($radiationId));
        expect($radiation->status->value)->toBe('cancelled')
            ->and($radiation->cancel_reason)->toBe('Radiation saisie par erreur.')
            ->and($radiation->cancelled_by)->toBe($manager->user_id)
            ->and($owner->fresh()?->status)->toBe(MembershipStatus::Active)
            ->and($owner->fresh()?->read_only_until)->toBeNull()
            ->and($invited->fresh()?->status)->toBe(MembershipStatus::Invited)
            ->and(app(MembershipExclusivity::class)->enrolledElsewhere($member->ncc, Organization::factory()->create()->id))->toBeTrue()
            ->and(AuditLog::query()->where('event', 'radiation_cancelled')->where('subject_id', $member->id)->value('context'))
            ->toEqual(['radiation_id' => $radiationId, 'was_validated' => true]);
        Event::assertDispatched(RadiationCancelled::class, fn (RadiationCancelled $event): bool => $event->wasValidated && $event->radiation->id === $radiationId);
    });

    it('RG-ADH-11 restaure un dossier basculé : portefeuille CGA, apporteur, contrat CGA, n° client gardé', function () {
        $manager = radiationManager();
        $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
        $member = radiationMember($manager, ['referrer_id' => $referrer->id]);
        app(TenantContext::class)->runAs($manager, fn () => app(CreateExpectedPieces::class)->execute($member));
        requestRadiation($manager, $member)->assertCreated();
        validateRadiation($manager, $member, 'moved_to_cabinet')->assertOk();

        cancelRadiation($manager, $member)
            ->assertOk()
            ->assertJsonPath('data.portfolio', 'cga')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.client_number', 'CAB-DCK-00001')
            ->assertJsonPath('data.contract.ended_on', null);

        $contract = app(TenantContext::class)->runAs($manager, fn () => $member->pieces()->where('piece_type', PieceType::SignedCgaContract)->sole());
        expect($member->fresh()?->referrer_id)->toBe($referrer->id)
            ->and($contract->status)->toBe(PieceStatus::Expected)
            ->and(app(TenantContext::class)->runAs($manager, fn () => PortfolioMovement::query()->where('member_id', $member->id)->exists()))->toBeFalse();
    });

    it('RG-ADH-11 annule une demande à tout moment, une radiation validée dans le délai paramétré seulement', function () {
        $manager = radiationManager();
        $requested = radiationMember($manager);
        $validated = radiationMember($manager);
        requestRadiation($manager, $requested)->assertCreated();
        requestRadiation($manager, $validated, ['reason' => 'death', 'validate_now' => '1', 'outcome' => 'closed'])->assertCreated();

        $this->travel(30)->days();
        $this->travel(-1)->minutes();
        radiationSpace($manager)->getJson("/api/v1/cga/members/{$validated->id}")->assertJsonPath('data.radiation.can.cancel', true);
        $this->travel(2)->minutes();
        radiationSpace($manager)->getJson("/api/v1/cga/members/{$validated->id}")->assertJsonPath('data.radiation.can.cancel', false);
        cancelRadiation($manager, $validated)->assertStatus(409)->assertJsonPath('code', 'radiation_cancel_expired')->assertJsonStructure(['details' => ['deadline']]);

        app(SettingsService::class)->set('members.radiation_cancel_days', 60);
        cancelRadiation($manager, $validated)->assertOk();
        $this->travel(200)->days();
        cancelRadiation($manager, $requested)->assertOk()->assertJsonPath('data.status', 'active');
    });

    it('RG-ADH-11 réserve l\'annulation au gérant, exige un motif, et refuse si le NCC a été repris entre-temps', function () {
        $manager = radiationManager();
        $member = radiationMember($manager);
        requestRadiation($manager, $member, ['reason' => 'death', 'validate_now' => '1', 'outcome' => 'closed'])->assertCreated();

        cancelRadiation(radiationAgent($manager, Role::CgaAccountantExpert), $member)->assertForbidden();
        cancelRadiation($manager, $member, null)->assertUnprocessable()->assertJsonValidationErrors('reason');
        cancelRadiation($manager, radiationMember($manager))->assertStatus(409)->assertJsonPath('code', 'radiation_not_found');

        $return = Member::factory()->create(['organization_id' => $manager->organization_id, 'ncc' => $member->ncc]);
        cancelRadiation($manager, $member)->assertStatus(409)->assertJsonPath('code', 'ncc_already_registered');
        $return->forceDelete();

        Member::factory()->active()->create(['ncc' => $member->ncc]);
        cancelRadiation($manager, $member)->assertStatus(409)->assertJsonPath('code', 'membership_exclusive');
        expect($member->fresh()?->status)->toBe(MemberStatus::Radiated);
    });
});
