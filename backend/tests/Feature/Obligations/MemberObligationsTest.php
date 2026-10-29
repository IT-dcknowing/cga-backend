<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Actions\SuggestMemberObligations;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/*
 * Obligations d'un dossier (06-IMPLEMENTATION-PLAN étape 4.2, F-OBL-02, RG-OBL-02, 03) : propositions
 * à l'inscription et à chaque changement de régime, d'effectif ou de secteur ; confirmation et retrait
 * par le CGA ; ajout manuel, dont une obligation locale (RG-OBL-01) ; droits et cloisonnement.
 * Catalogue d'essai : jamais le référentiel de DC-KNOWING.
 */

beforeEach(function () {
    Storage::fake();
});

function obligationsAgent(Role $role = Role::CgaManager, ?Organization $cga = null): Membership
{
    return Membership::factory()->role($role)->create($cga === null ? [] : ['organization_id' => $cga->id]);
}

/**
 * Catalogue d'essai : une obligation TEE, une RME, une CNPS (RG-OBL-03), une du secteur Transport.
 *
 * @return array<string, ObligationType>
 */
function essayCatalog(): array
{
    return [
        'tee' => ObligationType::factory()->applicableTo(['regimes' => ['TEE']])->create(['label' => 'A · Essai TEE']),
        'rme' => ObligationType::factory()->applicableTo(['regimes' => ['RME']])->create(['label' => 'B · Essai RME']),
        'cnps' => ObligationType::factory()->create(['label' => 'C · Essai CNPS', 'category' => ObligationCategory::Cnps]),
        'transport' => ObligationType::factory()->applicableTo(['sectors' => ['TRA001']])->create(['label' => 'D · Essai transport']),
    ];
}

/**
 * @return array<string, mixed>
 */
function obligationMemberForm(array $overrides = []): array
{
    return [
        'portfolio' => 'cga', 'entity_type' => 'company', 'company_name' => 'Ets Essai', 'legal_form' => 'SARL',
        'ncc' => '1864699A', 'regime' => 'TEE', 'main_activity' => 'Commerce de détail', 'phone' => '07 07 07 07 07', ...$overrides,
    ];
}

/**
 * @return array<string, string> code du type => statut, obligations du dossier
 */
function obligationStatuses(Membership $agent, string $memberId): array
{
    return app(TenantContext::class)->runAs($agent, static fn (): array => MemberObligation::query()
        ->with('type')
        ->where('member_id', $memberId)
        ->get()
        ->mapWithKeys(static fn (MemberObligation $obligation): array => [$obligation->type->label => $obligation->status->value])
        ->all());
}

describe('propositions (RG-OBL-02, 03)', function () {
    it('RG-OBL-02 · l\'inscription propose les obligations applicables au régime ; sans salarié, aucune CNPS (RG-OBL-03)', function () {
        $manager = obligationsAgent();
        essayCatalog();

        $id = inSpace($manager)->postJson('/api/v1/cga/members', obligationMemberForm())->assertCreated()->json('data.id');

        expect(obligationStatuses($manager, $id))->toBe(['A · Essai TEE' => 'suggested']);
        inSpace($manager)->getJson("/api/v1/cga/members/{$id}/obligations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'suggested')
            ->assertJsonPath('data.0.status_label', 'À confirmer')
            ->assertJsonPath('data.0.source', 'suggested')
            ->assertJsonPath('data.0.type.label', 'A · Essai TEE')
            ->assertJsonPath('data.0.type.local', false)
            ->assertJsonPath('data.0.can.confirm', true)
            ->assertJsonPath('data.0.can.remove', true);
    });

    it('RG-OBL-03 · effectif, secteur et régime modifiés : nouvelles propositions, anciennes propositions retirées', function () {
        $manager = obligationsAgent();
        essayCatalog();
        $transport = Sector::factory()->create(['code' => 'TRA001']);
        $id = inSpace($manager)->postJson('/api/v1/cga/members', obligationMemberForm())->json('data.id');

        inSpace($manager)->putJson("/api/v1/cga/members/{$id}", obligationMemberForm(['employees_count' => 3, 'sector_id' => $transport->id]))->assertOk();
        expect(obligationStatuses($manager, $id))->toBe([
            'A · Essai TEE' => 'suggested', 'C · Essai CNPS' => 'suggested', 'D · Essai transport' => 'suggested',
        ]);

        // Un brouillon change encore de régime par la fiche : la proposition TEE devient sans objet.
        inSpace($manager)->putJson("/api/v1/cga/members/{$id}", obligationMemberForm(['regime' => 'RME', 'employees_count' => 3, 'sector_id' => $transport->id]))->assertOk();
        expect(obligationStatuses($manager, $id))->toEqualCanonicalizing([
            'A · Essai TEE' => 'removed', 'B · Essai RME' => 'suggested', 'C · Essai CNPS' => 'suggested', 'D · Essai transport' => 'suggested',
        ]);

        // Rien de changé sur le régime, l'effectif ou le secteur : rien n'est recalculé.
        inSpace($manager)->putJson("/api/v1/cga/members/{$id}", obligationMemberForm(['regime' => 'RME', 'employees_count' => 3, 'sector_id' => $transport->id, 'main_activity' => 'Transport de marchandises']))->assertOk();
        expect(MemberObligation::query()->withoutGlobalScopes()->where('member_id', $id)->count())->toBe(4);
    });

    it('RG-OBL-02 · une obligation confirmée n\'est jamais retirée d\'office ; un dossier radié ne reçoit plus de proposition', function () {
        $manager = obligationsAgent();
        $catalog = essayCatalog();
        $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'regime' => TaxRegime::Tee]);
        $confirmed = MemberObligation::factory()->create(['member_id' => $member->id, 'obligation_type_id' => $catalog['tee']->id]);
        $suggest = app(SuggestMemberObligations::class);

        Tenancy::bypass(static function () use ($member): void {
            $member->regime = TaxRegime::Rme;
            $member->save();
        }, 'Test : reclassement simulé.');

        expect(app(TenantContext::class)->runAs($manager, fn () => $suggest->execute($member->refresh())))->toBe(['added' => 1, 'withdrawn' => 0])
            ->and(Tenancy::bypass(fn () => $confirmed->refresh()->status, 'Test : relecture.'))->toBe(MemberObligationStatus::Active);

        $radiated = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'status' => MemberStatus::Radiated]);
        expect(app(TenantContext::class)->runAs($manager, fn () => $suggest->execute($radiated)))->toBe(['added' => 0, 'withdrawn' => 0]);
    });

    it('n\'utilise que le catalogue national en vigueur et les obligations locales du centre', function () {
        $manager = obligationsAgent();
        ObligationType::factory()->create(['label' => 'Nationale active']);
        ObligationType::factory()->create(['label' => 'Nationale inactive', 'active' => false]);
        ObligationType::factory()->localTo($manager->organization)->create(['label' => 'Locale du centre']);
        ObligationType::factory()->localTo(Organization::factory()->create())->create(['label' => 'Locale d\'un autre CGA']);

        $id = inSpace($manager)->postJson('/api/v1/cga/members', obligationMemberForm())->json('data.id');

        expect(array_keys(obligationStatuses($manager, $id)))->toEqualCanonicalizing(['Nationale active', 'Locale du centre']);
    });
});

describe('décision du CGA (F-OBL-02)', function () {
    it('confirme des propositions, en retire d\'autres ; une obligation déjà traitée répond 409', function () {
        $manager = obligationsAgent();
        $catalog = essayCatalog();
        $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'regime' => TaxRegime::Rme, 'employees_count' => 2]);
        app(TenantContext::class)->runAs($manager, fn () => app(SuggestMemberObligations::class)->execute($member));
        [$rme, $cnps] = app(TenantContext::class)->runAs($manager, fn () => [
            MemberObligation::query()->where('obligation_type_id', $catalog['rme']->id)->sole(),
            MemberObligation::query()->where('obligation_type_id', $catalog['cnps']->id)->sole(),
        ]);

        inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations/review", ['confirm' => [$rme->id], 'remove' => [$cnps->id]])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.can.confirm', false)
            ->assertJsonPath('data.1.status', 'removed')
            ->assertJsonPath('data.1.can.remove', false);
        expect(app(TenantContext::class)->runAs($manager, fn () => $rme->refresh()->confirmed_by))->toBe($manager->user_id);

        inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations/review", ['confirm' => [$rme->id]])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_not_reviewable');
        inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations/review", ['remove' => [$cnps->id]])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_not_reviewable');
    });

    it('refuse une demande vide ou un même identifiant dans les deux listes (422)', function () {
        $manager = obligationsAgent();
        $obligation = MemberObligation::factory()->suggested()->create(['member_id' => Member::factory()->active()->create(['organization_id' => $manager->organization_id])->id]);

        inSpace($manager)->postJson("/api/v1/cga/members/{$obligation->member_id}/obligations/review", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['confirm', 'remove']);
        inSpace($manager)->postJson("/api/v1/cga/members/{$obligation->member_id}/obligations/review", ['confirm' => [$obligation->id], 'remove' => [$obligation->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('remove');
        inSpace($manager)->postJson("/api/v1/cga/members/{$obligation->member_id}/obligations/review", ['confirm' => ['pas-un-identifiant']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm.0');
    });

    it('retirer une obligation annule ses échéances à venir encore à faire, jamais les échéances passées', function () {
        $manager = obligationsAgent();
        $obligation = MemberObligation::factory()->create(['member_id' => Member::factory()->active()->create(['organization_id' => $manager->organization_id])->id]);
        $upcoming = ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, 'period_label' => 'A', 'due_date' => today()->addDays(10)]);
        $paid = ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, 'period_label' => 'B', 'due_date' => today()->addDays(10), 'kind' => 'payment', 'status' => InstanceStatus::PartiallyPaid, 'amount_due' => 100, 'amount_paid' => 40]);
        $past = ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, 'period_label' => 'C', 'due_date' => today()->subDays(10)]);

        inSpace($manager)->postJson("/api/v1/cga/members/{$obligation->member_id}/obligations/review", ['remove' => [$obligation->id]])->assertOk();

        Tenancy::bypass(static function () use ($upcoming, $paid, $past, $obligation): void {
            expect($upcoming->refresh()->status)->toBe(InstanceStatus::Cancelled)
                ->and($paid->refresh()->status)->toBe(InstanceStatus::PartiallyPaid)
                ->and($past->refresh()->status)->toBe(InstanceStatus::Todo)
                ->and($obligation->refresh()->end_date?->toDateString())->toBe(today()->toDateString());
        }, 'Test : relecture des échéances.');
    });

    it('ajoute une obligation à la main, dont une obligation locale du centre ; 409 si déjà attribuée ou indisponible', function () {
        $manager = obligationsAgent();
        $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id]);
        $local = ObligationType::factory()->localTo($manager->organization)->create();
        $inactive = ObligationType::factory()->create(['active' => false]);
        $foreign = ObligationType::factory()->localTo(Organization::factory()->create())->create();

        inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations", ['obligation_type_id' => $local->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.type.local', true);
        inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations", ['obligation_type_id' => $local->id])
            ->assertStatus(409)->assertJsonPath('code', 'obligation_already_assigned');

        foreach ([$inactive->id, $foreign->id, strtolower((string) str()->ulid())] as $unavailable) {
            inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations", ['obligation_type_id' => $unavailable])
                ->assertStatus(409)->assertJsonPath('code', 'obligation_type_unavailable');
        }
        inSpace($manager)->postJson("/api/v1/cga/members/{$member->id}/obligations", [])
            ->assertUnprocessable()->assertJsonValidationErrors('obligation_type_id');
    });
});

describe('droits et cloisonnement', function () {
    it('le formateur lit les obligations sans pouvoir les gérer ; un dossier radié ne se gère plus', function () {
        $manager = obligationsAgent();
        $trainer = obligationsAgent(Role::CgaTrainer, $manager->organization);
        $obligation = MemberObligation::factory()->suggested()->create(['member_id' => Member::factory()->active()->create(['organization_id' => $manager->organization_id])->id]);

        inSpace($trainer)->getJson("/api/v1/cga/members/{$obligation->member_id}/obligations")
            ->assertOk()->assertJsonPath('data.0.can.confirm', false)->assertJsonPath('data.0.can.remove', false);
        inSpace($trainer)->postJson("/api/v1/cga/members/{$obligation->member_id}/obligations/review", ['confirm' => [$obligation->id]])->assertForbidden();
        inSpace($trainer)->postJson("/api/v1/cga/members/{$obligation->member_id}/obligations", ['obligation_type_id' => $obligation->obligation_type_id])->assertForbidden();

        $radiated = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'status' => MemberStatus::Radiated]);
        inSpace($manager)->postJson("/api/v1/cga/members/{$radiated->id}/obligations", ['obligation_type_id' => $obligation->obligation_type_id])->assertForbidden();
    });

    it('RG-SEC-02 · le dossier d\'un autre CGA répond 404 ; l\'espace CNCGA n\'y a pas accès', function () {
        $manager = obligationsAgent();
        $foreign = MemberObligation::factory()->suggested()->create();

        inSpace($manager)->getJson("/api/v1/cga/members/{$foreign->member_id}/obligations")->assertNotFound();
        inSpace($manager)->postJson("/api/v1/cga/members/{$foreign->member_id}/obligations/review", ['confirm' => [$foreign->id]])->assertNotFound();
        inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson('/api/v1/cga/obligation-types')->assertForbidden();
    });
});
