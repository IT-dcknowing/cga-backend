<?php

use App\Domain\Closing\Actions\OpenClosingCampaign;
use App\Domain\Closing\Actions\OpenClosingCampaigns;
use App\Domain\Closing\Actions\SendClosingReminders;
use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Events\ClosingPiecesReminderDue;
use App\Domain\Closing\Events\ClosingStepsLate;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Campagne de clôture (06-IMPLEMENTATION-PLAN étape 4.10, F-OBL-08, RG-OBL-10 à 12, décision 0006) :
 * ouverture rejouable avec le calendrier en vigueur (réseau, ajusté par le CGA ; dépôt légal du
 * CNCGA), suivi des dossiers actifs des deux portefeuilles, étapes dans l'ordre, dépôt avec référence
 * et accusé, tableau de suivi, nombres du rapport sur le seul portefeuille CGA, rappels, droits.
 */

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2027-01-02 09:00:00');
});

function closingCentre(): Membership
{
    return Membership::factory()->role(Role::CgaManager)->create();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function closingMember(Organization $cga, array $attributes = []): Member
{
    return Member::factory()->active()->create(['organization_id' => $cga->id, ...$attributes]);
}

function openCampaign(Organization $cga, int $year = 2026): ClosingCampaign
{
    return Tenancy::bypass(static fn () => app(OpenClosingCampaign::class)->execute($cga->id, $year, CarbonImmutable::now())['campaign'], 'Test : ouverture.');
}

function itemOf(ClosingCampaign $campaign, Member $member): ClosingCampaignItem
{
    return Tenancy::bypass(static fn () => ClosingCampaignItem::query()->where('campaign_id', $campaign->id)->where('member_id', $member->id)->sole(), 'Test.');
}

function stepUrl(ClosingCampaignItem $item, string $step): string
{
    return "/api/v1/cga/closing-campaigns/{$item->campaign_id}/items/{$item->id}/steps/{$step}";
}

describe('ouverture de la campagne (RG-OBL-10)', function () {
    it('RG-OBL-10 · ouvre l\'exercice avec le calendrier du réseau ajusté par le CGA, dépôt légal du CNCGA', function () {
        $agent = closingCentre();
        app(SettingsService::class)->set('closing.calendar', ['pieces' => '01-31', 'regularisation' => '02-27', 'finalisation' => '03-30'], $agent->organization_id);
        app(SettingsService::class)->set('closing.filing_date', '05-29');

        expect(openCampaign($agent->organization)->steps)->toBe([
            ['code' => 'pieces', 'due' => '2027-01-31'],
            ['code' => 'regularisation', 'due' => '2027-02-27'],
            ['code' => 'finalisation', 'due' => '2027-03-30'],
            ['code' => 'filing', 'due' => '2027-05-29'],
        ])->and(openCampaign(Organization::factory()->create())->steps[0])->toBe(['code' => 'pieces', 'due' => '2027-01-30']);
        expect(fn () => app(SettingsService::class)->set('closing.filing_date', '06-30', $agent->organization_id))->toThrow(BusinessRuleException::class);
    });

    it('RG-OBL-10 · suit les dossiers actifs des deux portefeuilles, livrable selon le régime ; rejouable', function () {
        $cga = closingCentre()->organization;
        $tee = closingMember($cga, ['regime' => TaxRegime::Tee]);
        $rni = closingMember($cga, ['regime' => TaxRegime::Rni, 'portfolio' => Portfolio::Cabinet]);
        $pending = closingMember($cga, ['regime' => TaxRegime::Rme, 'status' => MemberStatus::RadiationPending]);
        closingMember($cga, ['status' => MemberStatus::Draft]);
        closingMember($cga, ['status' => MemberStatus::Radiated]);

        $campaign = openCampaign($cga);
        expect(itemOf($campaign, $tee))->deliverable->toBe(ClosingDeliverable::Smt)->portfolio->toBe(Portfolio::Cga)
            ->and(itemOf($campaign, $rni))->deliverable->toBe(ClosingDeliverable::FinancialStatements)->portfolio->toBe(Portfolio::Cabinet)
            ->and(itemOf($campaign, $pending)->deliverable)->toBe(ClosingDeliverable::Smt)
            ->and(Tenancy::bypass(static fn () => ClosingCampaignItem::query()->count(), 'Test.'))->toBe(3);

        $late = closingMember($cga);
        $again = openCampaign($cga);
        expect($again->is($campaign))->toBeTrue()
            ->and(Tenancy::bypass(static fn () => ClosingCampaignItem::query()->count(), 'Test.'))->toBe(4)
            ->and(itemOf($campaign, $late)->deliverable)->toBe(ClosingDeliverable::Smt);
    });

    it('closing:open-campaign ouvre l\'exercice précédent dans chaque CGA non résilié, le 2 janvier ; journalise une erreur', function () {
        $first = closingCentre()->organization;
        $terminated = Organization::factory()->create(['status' => OrganizationStatus::Terminated]);
        closingMember($first);

        $this->artisan('closing:open-campaign')->assertSuccessful()->expectsOutputToContain('1 dossier(s) ajouté(s)');
        $this->artisan('closing:open-campaign --year=2025')->assertSuccessful();
        $this->artisan('closing:open-campaign --year=25')->assertExitCode(2);

        Tenancy::bypass(function () use ($first, $terminated): void {
            expect(ClosingCampaign::query()->where('organization_id', $first->id)->pluck('fiscal_year')->sort()->values()->all())->toBe([2025, 2026])
                ->and(ClosingCampaign::query()->where('organization_id', $terminated->id)->exists())->toBeFalse();
        }, 'Test.');
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'closing:open-campaign'));
        expect($events->first()->expression)->toBe('45 0 2 1 *');

        Log::spy();
        $this->mock(OpenClosingCampaigns::class)->shouldReceive('execute')->andThrow(new RuntimeException('panne'));
        expect(fn () => $this->artisan('closing:open-campaign')->run())->toThrow(RuntimeException::class, 'panne');
        Log::shouldHaveReceived('error')->once();
    });

    it('garde des campagnes cohérentes : une par CGA et par exercice, un suivi par dossier', function () {
        $campaign = ClosingCampaign::factory()->create();
        $item = ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id]);

        expect(fn () => DB::transaction(fn () => ClosingCampaign::factory()->create(['organization_id' => $campaign->organization_id])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, 'member_id' => $item->member_id])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('closing_campaign_items')->where('id', $item->id)->update(['deliverable' => 'bilan'])))->toThrow(QueryException::class);
    });
});

describe('étapes d\'un dossier (RG-OBL-10, décision 0006)', function () {
    it('RG-OBL-10 · étapes dans l\'ordre ; dépôt avec référence et accusé ; une étape faite ne se refait pas', function () {
        $agent = closingCentre();
        $member = closingMember($agent->organization);
        $item = itemOf(openCampaign($agent->organization), $member);

        inSpace($agent)->postJson(stepUrl($item, 'finalisation'), ['done_on' => '2027-01-02'])
            ->assertStatus(409)->assertJsonPath('code', 'closing_step_out_of_order');
        inSpace($agent)->postJson(stepUrl($item, 'pieces'), ['done_on' => '2027-01-02'])
            ->assertOk()->assertJsonPath('data.steps.0.done_on', '2027-01-02')->assertJsonPath('data.next_step', 'regularisation');
        inSpace($agent)->postJson(stepUrl($item, 'pieces'), ['done_on' => '2027-01-02'])->assertStatus(409)->assertJsonPath('code', 'closing_step_already_done');
        inSpace($agent)->postJson(stepUrl($item, 'regularisation'), ['done_on' => '2027-01-02'])->assertOk();
        inSpace($agent)->postJson(stepUrl($item, 'finalisation'), ['done_on' => '2027-01-02'])->assertOk();

        inSpace($agent)->postJson(stepUrl($item, 'filing'), ['done_on' => '2027-01-02'])->assertUnprocessable()->assertJsonValidationErrors(['reference', 'receipt']);
        inSpace($agent)->post(stepUrl($item, 'filing'), ['done_on' => '2027-01-02', 'reference' => 'DEP-2027-0042', 'receipt' => UploadedFile::fake()->createWithContent('accuse.pdf', "%PDF-1.4\n%%EOF")])
            ->assertOk()
            ->assertJsonPath('data.next_step', null)
            ->assertJsonPath('data.filing_reference', 'DEP-2027-0042')
            ->assertJsonPath('data.filing_receipt.name', 'accuse.pdf')
            ->assertJsonPath('data.can.mark_step', false);
        inSpace($agent)->postJson(stepUrl($item, 'pieces'), ['done_on' => '2027-01-03'])->assertUnprocessable()->assertJsonValidationErrors('done_on');
        inSpace($agent)->postJson(stepUrl($item, 'inconnue'), ['done_on' => '2027-01-02'])->assertNotFound();
    });

    it('le livrable se change et le dossier s\'attribue à un agent du centre', function () {
        $agent = closingCentre();
        $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $agent->organization_id]);
        $member = closingMember($agent->organization);
        $item = itemOf(openCampaign($agent->organization), $member);
        $url = "/api/v1/cga/closing-campaigns/{$item->campaign_id}/items/{$item->id}";

        inSpace($agent)->putJson($url, ['deliverable' => 'financial_statements', 'assigned_to' => $accountant->user_id])
            ->assertOk()->assertJsonPath('data.deliverable', 'financial_statements')->assertJsonPath('data.assigned_to.id', $accountant->user_id);
        inSpace($agent)->putJson($url, ['deliverable' => 'smt', 'assigned_to' => null])->assertOk()->assertJsonPath('data.assigned_to', null);
        inSpace($agent)->putJson($url, ['deliverable' => 'smt', 'assigned_to' => closingCentre()->user_id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        inSpace($agent)->putJson($url, ['deliverable' => 'bilan'])->assertUnprocessable()->assertJsonValidationErrors(['deliverable', 'assigned_to']);
    });

    it('une campagne close ne change plus', function () {
        $agent = closingCentre();
        $member = closingMember($agent->organization);
        $campaign = openCampaign($agent->organization);
        $item = itemOf($campaign, $member);
        Tenancy::bypass(static fn () => $campaign->forceFill(['status' => 'closed', 'closed_at' => now()])->save(), 'Test.');

        inSpace($agent)->postJson(stepUrl($item, 'pieces'), ['done_on' => '2027-01-02'])->assertStatus(409)->assertJsonPath('code', 'closing_campaign_closed');
        inSpace($agent)->putJson("/api/v1/cga/closing-campaigns/{$campaign->id}/items/{$item->id}", ['deliverable' => 'smt', 'assigned_to' => null])
            ->assertStatus(409)->assertJsonPath('code', 'closing_campaign_closed');
    });
});

describe('actions groupées de /cga/cloture', function () {
    it('RG-OBL-10 · marque l\'étape d\'une sélection, écarte avec leur motif les dossiers hors ordre ou déjà faits', function () {
        $agent = closingCentre();
        $cga = $agent->organization;
        [$first, $second, $third] = [closingMember($cga), closingMember($cga), closingMember($cga)];
        $campaign = openCampaign($cga);
        inSpace($agent)->postJson(stepUrl(itemOf($campaign, $second), 'pieces'), ['done_on' => '2027-01-02'])->assertOk();
        $ids = [itemOf($campaign, $first)->id, itemOf($campaign, $second)->id, itemOf($campaign, $third)->id];
        $url = "/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/steps/pieces";

        $response = inSpace($agent)->postJson($url, ['item_ids' => $ids, 'done_on' => '2027-01-02'])->assertOk()->assertJsonCount(2, 'data');
        expect($response->json('meta.skipped'))->toHaveCount(1)
            ->and($response->json('meta.skipped.0'))->toMatchArray(['id' => $ids[1], 'code' => 'closing_step_already_done']);

        $response = inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/steps/finalisation", ['item_ids' => $ids, 'done_on' => '2027-01-02'])
            ->assertOk()->assertJsonCount(0, 'data');
        expect($response->json('meta.skipped.0.code'))->toBe('closing_step_out_of_order')
            ->and($response->json('meta.skipped.0.message'))->toContain('Régularisation');

        inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/steps/filing", ['item_ids' => $ids, 'done_on' => '2027-01-02'])
            ->assertUnprocessable()->assertJsonValidationErrors('step');
        inSpace($agent)->postJson($url, ['item_ids' => [], 'done_on' => '2027-01-03'])->assertUnprocessable()->assertJsonValidationErrors(['item_ids', 'done_on']);
        inSpace($agent)->postJson($url, ['item_ids' => [$ids[0], $ids[0]], 'done_on' => '2027-01-02'])->assertUnprocessable()->assertJsonValidationErrors('item_ids.1');
        inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/steps/inconnue", ['item_ids' => $ids, 'done_on' => '2027-01-02'])->assertNotFound();
    });

    it('affecte une sélection à un agent du centre, ou à personne', function () {
        $agent = closingCentre();
        $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $agent->organization_id]);
        [$first, $second] = [closingMember($agent->organization), closingMember($agent->organization)];
        $campaign = openCampaign($agent->organization);
        $ids = [itemOf($campaign, $first)->id, itemOf($campaign, $second)->id];
        $url = "/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/assign";

        inSpace($agent)->postJson($url, ['item_ids' => $ids, 'assigned_to' => $accountant->user_id])
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.assigned_to.id', $accountant->user_id);
        expect(itemOf($campaign, $first)->assigned_to)->toBe($accountant->user_id);
        inSpace($agent)->postJson($url, ['item_ids' => $ids, 'assigned_to' => null])->assertOk()->assertJsonPath('data.0.assigned_to', null);
        inSpace($agent)->postJson($url, ['item_ids' => $ids, 'assigned_to' => closingCentre()->user_id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        inSpace($agent)->postJson($url, ['item_ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    });

    it('RG-SEC-02 · un dossier d\'une autre campagne ou d\'un autre CGA est refusé ; campagne close, secrétaire', function () {
        $agent = closingCentre();
        $member = closingMember($agent->organization);
        $campaign = openCampaign($agent->organization);
        $other = openCampaign($agent->organization, 2025);
        $foreign = ClosingCampaignItem::factory()->create();
        $own = itemOf($campaign, $member);

        foreach ([itemOf($other, $member)->id, $foreign->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/assign", ['item_ids' => [$id], 'assigned_to' => null])
                ->assertUnprocessable()->assertJsonValidationErrors('item_ids.0');
        }
        inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$foreign->campaign_id}/bulk/assign", ['item_ids' => [$foreign->id], 'assigned_to' => null])->assertNotFound();

        $secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $agent->organization_id]);
        inSpace($secretary)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/steps/pieces", ['item_ids' => [$own->id], 'done_on' => '2027-01-02'])->assertForbidden();

        Tenancy::bypass(static fn () => $campaign->forceFill(['status' => 'closed', 'closed_at' => now()])->save(), 'Test.');
        inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/steps/pieces", ['item_ids' => [$own->id], 'done_on' => '2027-01-02'])
            ->assertStatus(409)->assertJsonPath('code', 'closing_campaign_closed');
        inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$campaign->id}/bulk/assign", ['item_ids' => [$own->id], 'assigned_to' => null])
            ->assertStatus(409)->assertJsonPath('code', 'closing_campaign_closed');
    });
});

describe('suivi et nombres du rapport (RG-OBL-11, 12)', function () {
    it('RG-OBL-11 · tableau de suivi : dossiers faits et en retard par étape, filtres portefeuille et agent', function () {
        $agent = closingCentre();
        $cga = $agent->organization;
        $done = closingMember($cga);
        $waiting = closingMember($cga);
        $cabinet = closingMember($cga, ['portfolio' => Portfolio::Cabinet, 'regime' => TaxRegime::Rsi]);
        $campaign = openCampaign($cga);
        inSpace($agent)->postJson(stepUrl(itemOf($campaign, $done), 'pieces'), ['done_on' => '2027-01-02'])->assertOk();
        inSpace($agent)->getJson("/api/v1/cga/closing-campaigns/{$campaign->id}/items?filter[late]=1")->assertOk()->assertJsonCount(0, 'data');
        $this->travelTo('2027-02-01 09:00:00');
        $this->flushSession();
        auth()->forgetGuards();

        inSpace($agent)->getJson("/api/v1/cga/closing-campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('data.fiscal_year', 2026)
            ->assertJsonPath('data.steps.3', ['code' => 'filing', 'label' => 'Dépôt légal', 'due' => '2027-05-30'])
            ->assertJsonPath('meta.board.items', 3)
            ->assertJsonPath('meta.board.steps.0', ['code' => 'pieces', 'done' => 1, 'late' => 2])
            ->assertJsonPath('meta.board.steps.1', ['code' => 'regularisation', 'done' => 0, 'late' => 0]);
        inSpace($agent)->getJson("/api/v1/cga/closing-campaigns/{$campaign->id}?portfolio=cabinet")
            ->assertOk()->assertJsonPath('meta.board.items', 1)->assertJsonPath('meta.board.steps.0.late', 1);

        $items = "/api/v1/cga/closing-campaigns/{$campaign->id}/items";
        inSpace($agent)->getJson("{$items}?filter[late]=1")->assertOk()->assertJsonCount(2, 'data');
        inSpace($agent)->getJson("{$items}?filter[step]=regularisation")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.member.id', $done->id);
        inSpace($agent)->getJson("{$items}?filter[portfolio]=cabinet")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.member.id', $cabinet->id)->assertJsonPath('data.0.late_step', 'pieces');
        inSpace($agent)->getJson("{$items}?filter[step]=done")->assertOk()->assertJsonCount(0, 'data');
        inSpace($agent)->getJson("{$items}?filter[step]=autre&filter[portfolio]=x")->assertUnprocessable()->assertJsonValidationErrors(['filter.step', 'filter.portfolio']);
        expect($waiting)->toBeInstanceOf(Member::class);
    });

    it('RG-OBL-12 · SMT et états financiers établis et transmis : portefeuille CGA seulement', function () {
        $campaign = ClosingCampaign::factory()->create();
        $make = static fn (array $attributes) => ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, ...$attributes]);
        $finalised = ['step_pieces_at' => '2027-01-10', 'step_regularisation_at' => '2027-02-10', 'step_finalisation_at' => '2027-03-10'];
        $make($finalised);
        $make([...$finalised, 'step_filing_at' => '2027-05-10']);
        $make([...$finalised, 'deliverable' => ClosingDeliverable::FinancialStatements, 'step_filing_at' => '2027-05-10']);
        $make([...$finalised, 'portfolio' => Portfolio::Cabinet, 'step_filing_at' => '2027-05-10']);
        $agent = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $campaign->organization_id]);

        inSpace($agent)->getJson("/api/v1/cga/closing-campaigns/{$campaign->id}")->assertOk()->assertJsonPath('meta.report', [
            'smt_established' => 2, 'smt_transmitted' => 1, 'financial_statements_established' => 1, 'financial_statements_transmitted' => 1,
        ]);
    });

    it('RG-OBL-11 · reminders:closing remet les rappels de pièces et le relevé des retards ; chaque jour à 07:35', function () {
        Event::fake([ClosingPiecesReminderDue::class, ClosingStepsLate::class]);
        $agent = closingCentre();
        $campaign = openCampaign($agent->organization);
        $member = closingMember($agent->organization);
        openCampaign($agent->organization);
        $this->travelTo('2027-01-15 07:35:00');

        $this->artisan('reminders:closing')->assertSuccessful()->expectsOutputToContain('1 rappel(s) de pièces, 0 dossier(s) en retard');
        Event::assertDispatched(ClosingPiecesReminderDue::class, fn ($event) => $event->item->member_id === $member->id && $event->reason === 'before');

        $this->travelTo('2027-02-06 07:35:00');
        $this->artisan('reminders:closing')->assertSuccessful()->expectsOutputToContain('1 dossier(s) en retard');
        Event::assertDispatched(ClosingStepsLate::class, fn ($event) => $event->campaign->is($campaign) && $event->late === ['pieces' => 1]);
        expect(collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'reminders:closing'))->expression)->toBe('35 7 * * *');

        Log::spy();
        $this->mock(SendClosingReminders::class)->shouldReceive('execute')->andThrow(new RuntimeException('panne'));
        expect(fn () => $this->artisan('reminders:closing')->run())->toThrow(RuntimeException::class, 'panne');
        Log::shouldHaveReceived('error')->once();
    });
});

describe('dossier, espace adhérent, droits', function () {
    it('onglet Clôture du dossier : ses exercices ; l\'adhérent voit ses étapes, sans données internes', function () {
        $agent = closingCentre();
        $member = closingMember($agent->organization);
        openCampaign($agent->organization, 2025);
        openCampaign($agent->organization);
        $access = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $member->organization_id, 'member_id' => $member->id]);

        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}/closing")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.campaign.fiscal_year', 2026)->assertJsonPath('data.0.can.mark_step', true);
        inSpace($access)->getJson('/api/v1/member/closing')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.steps.0.code', 'pieces')
            ->assertJsonMissingPath('data.0.portfolio')->assertJsonMissingPath('data.0.can');

        Tenancy::bypass(static fn () => $access->forceFill(['status' => MembershipStatus::ReadOnly])->save(), 'Test : adhérent radié.');
        inSpace($access)->getJson('/api/v1/member/closing')->assertForbidden();
    });

    it('lecture avec closing.view, saisie avec closing.manage', function (Role $role, int $read, int $write) {
        $manager = closingCentre();
        $agent = Membership::factory()->role($role)->create(['organization_id' => $manager->organization_id]);
        $member = closingMember($manager->organization);
        $item = itemOf(openCampaign($manager->organization), $member);

        inSpace($agent)->getJson('/api/v1/cga/closing-campaigns')->assertStatus($read);
        inSpace($agent)->postJson(stepUrl($item, 'pieces'), ['done_on' => '2027-01-02'])->assertStatus($write);
    })->with([
        'gérant' => [Role::CgaManager, 200, 200],
        'expert-comptable' => [Role::CgaAccountantExpert, 200, 200],
        'comptable' => [Role::CgaAccountant, 200, 200],
        'secrétaire' => [Role::CgaSecretary, 200, 403],
        'chargé des honoraires' => [Role::CgaBillingOfficer, 403, 403],
        'formateur' => [Role::CgaTrainer, 403, 403],
    ]);

    it('RG-SEC-02 · la campagne ou le dossier d\'un autre CGA répond 404', function () {
        $agent = closingCentre();
        $own = openCampaign($agent->organization);
        $foreign = ClosingCampaignItem::factory()->create();

        inSpace($agent)->getJson("/api/v1/cga/closing-campaigns/{$foreign->campaign_id}")->assertNotFound();
        inSpace($agent)->postJson("/api/v1/cga/closing-campaigns/{$own->id}/items/{$foreign->id}/steps/pieces", ['done_on' => '2027-01-02'])->assertNotFound();
        inSpace(Membership::factory()->role(Role::CncgaManager)->create())->getJson('/api/v1/cga/closing-campaigns')->assertForbidden();
    });
});
