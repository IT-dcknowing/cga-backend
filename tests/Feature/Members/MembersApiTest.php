<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\RevenueSource;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\FeeSchedule;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Dossiers des adhérents (06-IMPLEMENTATION-PLAN étape 2.2, F-ADH-01 à 04) : inscription en brouillon,
 * modification avec justificatif, suppression d'un brouillon, NCC normalisé, doublons.
 */

function cgaAgent(Role $role = Role::CgaManager, ?Organization $cga = null): Membership
{
    return Membership::factory()->role($role)->create($cga === null ? [] : ['organization_id' => $cga->id]);
}

/**
 * @return array<string, mixed>
 */
function memberForm(array $overrides = []): array
{
    return [
        'portfolio' => 'cga', 'entity_type' => 'company', 'company_name' => 'Ets Kouadio & Fils', 'legal_form' => 'SARL',
        'ncc' => '1864699 a', 'regime' => 'TEE', 'main_activity' => 'Commerce de détail', 'phone' => '07 07 07 07 07', ...$overrides,
    ];
}

/**
 * Lecture d'un dossier hors requête, dans l'espace d'un agent.
 */
function inCentre(Membership $agent, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs($agent, $callback);
}

beforeEach(function () {
    Storage::fake();
});

describe('inscription (F-ADH-02)', function () {
    it('RG-ADH-01 enregistre un brouillon sans numéro, fiche mise en forme, montants du barème', function () {
        $manager = cgaAgent();
        inCentre($manager, function (): void {
            foreach ([[FeeKind::EntryFee, null, 30_000, null], [FeeKind::Fees, TaxRegime::Tee, 20_000, 'quarterly']] as [$kind, $regime, $amount, $periodicity]) {
                $schedule = new FeeSchedule;
                $schedule->kind = $kind;
                $schedule->regime = $regime;
                $schedule->amount = $amount;
                $schedule->setAttribute('periodicity', $periodicity);
                $schedule->valid_from = today()->subYear();
                $schedule->save();
            }
        });

        $response = inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['reference_revenue' => ['year' => 2025, 'amount' => 24_500_000, 'kind' => 'actual']]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.membership_number', null)
            ->assertJsonPath('data.company_name', 'ETS KOUADIO & FILS')
            ->assertJsonPath('data.sigle', 'EKF')
            ->assertJsonPath('data.ncc', '1864699A')
            ->assertJsonPath('data.phone', '+2250707070707')
            ->assertJsonPath('data.whatsapp', '+2250707070707')
            ->assertJsonPath('data.entry_fee_amount', 30_000)
            ->assertJsonPath('data.fees_amount', 20_000)
            ->assertJsonPath('data.fees_periodicity', 'quarterly')
            ->assertJsonPath('data.reference_revenue', ['year' => 2025, 'amount' => 24_500_000, 'kind' => 'actual', 'source' => 'entry'])
            ->assertJsonPath('data.can.delete', true);

        expect(AuditLog::query()->where('event', 'created')->where('subject_id', $response->json('data.id'))->exists())->toBeTrue();
    });

    it('garde les montants saisis, et le contrat CGA confirmé par son auteur', function () {
        $manager = cgaAgent();

        $id = inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['entry_fee_amount' => 25_000, 'fees_amount' => 0, 'cga_contract_signed_on' => '2026-03-01', 'cga_contract_confirmed' => true]))
            ->assertCreated()
            ->assertJsonPath('data.entry_fee_amount', 25_000)
            ->assertJsonPath('data.contract', ['signed_on' => '2026-03-01', 'confirmed' => true, 'ended_on' => null])
            ->json('data.id');

        expect(inCentre($manager, fn () => Member::query()->findOrFail($id)->cga_contract_confirmed_by))->toBe($manager->user_id);
    });

    it('valide la saisie : champs du dossier, NCC, régime du portefeuille, apporteur et contrat du CGA seulement', function () {
        $manager = cgaAgent();
        $referrer = BusinessReferrer::factory()->create(['organization_id' => $manager->organization_id]);
        $otherReferrer = BusinessReferrer::factory()->create();

        inSpace($manager)->postJson('/api/v1/cga/members', ['portfolio' => 'cga'])
            ->assertUnprocessable()->assertJsonValidationErrors(['entity_type', 'company_name', 'legal_form', 'ncc', 'regime', 'main_activity', 'phone']);
        inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['ncc' => '123456A', 'regime' => 'RSI', 'referrer_id' => $otherReferrer->id, 'phone' => '12']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ncc.0', 'Le NCC compte 7 chiffres suivis d\'une lettre (ex. 1234567A).')
            ->assertJsonPath('errors.regime.0', 'Au portefeuille CGA, le régime est RME ou TEE ; le régime réel (RSI, RNI) relève du portefeuille Cabinet.')
            ->assertJsonValidationErrors(['referrer_id', 'phone']);
        inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['portfolio' => 'cabinet', 'regime' => 'RSI', 'referrer_id' => $referrer->id, 'cga_contract_signed_on' => '2026-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['referrer_id', 'cga_contract_signed_on'])->assertJsonMissingValidationErrors('regime');
        inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['portfolio' => 'cabinet', 'regime' => 'RNI']))
            ->assertCreated()->assertJsonPath('data.portfolio', 'cabinet')->assertJsonPath('data.regime', 'RNI');
    });

    it('n\'accepte qu\'un secteur validé ou proposé par le centre, et un CDI actif', function () {
        $manager = cgaAgent();
        $proposedElsewhere = Sector::factory()->create(['status' => 'proposed', 'proposed_by_organization_id' => Organization::factory()->create()->id]);
        $proposedHere = Sector::factory()->create(['status' => 'proposed', 'proposed_by_organization_id' => $manager->organization_id]);
        $inactive = TaxOffice::factory()->create(['active' => false]);

        inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['sector_id' => $proposedElsewhere->id, 'tax_office_id' => $inactive->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['sector_id', 'tax_office_id']);
        inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['sector_id' => $proposedHere->id]))
            ->assertCreated()->assertJsonPath('data.sector.id', $proposedHere->id);
    });
});

describe('doublons (RG-ADH-05)', function () {
    it('RG-ADH-05 n\'ouvre qu\'un dossier par NCC dans le centre, et désigne le dossier existant', function () {
        $manager = cgaAgent();
        $existing = Member::factory()->cabinet()->create(['organization_id' => $manager->organization_id, 'ncc' => '1864699A']);

        inSpace($manager)->postJson('/api/v1/cga/members', memberForm())
            ->assertStatus(409)
            ->assertJsonPath('code', 'ncc_already_registered')
            ->assertJsonPath('details', ['member_id' => $existing->id, 'status' => 'draft', 'portfolio' => 'cabinet']);
        inSpace($manager)->getJson('/api/v1/cga/members/ncc-check?ncc=1864699 a')
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.existing.id', $existing->id)
            ->assertJsonPath('data.enrolled_elsewhere', false);

        DB::table('members')->where('id', $existing->id)->update(['status' => MemberStatus::Closed->value]);
        inSpace($manager)->postJson('/api/v1/cga/members', memberForm())->assertCreated();
    });

    it('RG-ADH-05 refuse au portefeuille CGA un contribuable adhérent d\'un autre CGA abonné, sans le nommer', function () {
        $manager = cgaAgent();
        Member::factory()->active()->create(['ncc' => '1864699A']);

        inSpace($manager)->postJson('/api/v1/cga/members', memberForm())
            ->assertStatus(409)
            ->assertJsonPath('code', 'membership_exclusive')
            ->assertJsonPath('message', 'Ce contribuable est adhérent d\'un autre CGA. L\'inscription sera possible dès que sa radiation y aura été validée.')
            ->assertJsonMissingPath('details.organization');
        inSpace($manager)->getJson('/api/v1/cga/members/ncc-check?ncc=1864699A')->assertJsonPath('data.enrolled_elsewhere', true)->assertJsonPath('data.existing', null);
        expect(AuditLog::query()->where('event', 'tenancy_bypass')->exists())->toBeTrue();

        inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['portfolio' => 'cabinet']))->assertCreated();
    });

    it('ne compte ni un CGA non abonné, ni un dossier Cabinet ou radié d\'un autre CGA', function () {
        $manager = cgaAgent();
        Member::factory()->active()->create(['ncc' => '1111111A', 'organization_id' => Organization::factory()->create(['subscription_status' => SubscriptionStatus::None])->id]);
        Member::factory()->cabinet()->create(['ncc' => '2222222A', 'status' => MemberStatus::Active]);
        Member::factory()->active()->create(['ncc' => '3333333A', 'status' => MemberStatus::Radiated]);

        foreach (['1111111A', '2222222A', '3333333A'] as $ncc) {
            inSpace($manager)->postJson('/api/v1/cga/members', memberForm(['ncc' => $ncc]))->assertCreated();
        }
        inSpace($manager)->getJson('/api/v1/cga/members/ncc-check?ncc=abc')->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.enrolled_elsewhere', false);
    });
});

describe('modification (F-ADH-04)', function () {
    it('modifie librement un brouillon, régime compris', function () {
        $manager = cgaAgent();
        $draft = Member::factory()->create(['organization_id' => $manager->organization_id]);

        inSpace($manager)->putJson("/api/v1/cga/members/{$draft->id}", memberForm(['company_name' => 'Nouveau nom', 'regime' => 'RME']))
            ->assertOk()->assertJsonPath('data.company_name', 'NOUVEAU NOM')->assertJsonPath('data.regime', 'RME');
    });

    it('RG-ADH-06b exige un justificatif pour changer la raison sociale ou le NCC d\'un dossier validé, et le joint au journal', function () {
        $manager = cgaAgent();
        $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'ncc' => '1864699A', 'regime' => 'TEE']);
        $form = memberForm(['company_name' => 'Raison sociale corrigée']);

        inSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", $form)
            ->assertUnprocessable()
            ->assertJsonPath('errors.justification.0', 'Joignez une pièce justificative pour modifier : raison sociale.');
        inSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", memberForm(['company_name' => $member->company_name, 'regime' => 'RME']))
            ->assertUnprocessable()->assertJsonValidationErrors('regime');

        inSpace($manager)->post("/api/v1/cga/members/{$member->id}", [...$form, '_method' => 'PUT', 'justification' => UploadedFile::fake()->createWithContent('kbis.pdf', "%PDF-1.4\n%%EOF")], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.company_name', 'RAISON SOCIALE CORRIGÉE');

        $entry = AuditLog::query()->where('event', 'updated')->where('subject_id', $member->id)->latest('id')->firstOrFail();
        expect(DB::table('audit_attachments')->where('audit_log_id', $entry->id)->exists())->toBeTrue()
            ->and($entry->changes)->toHaveKey('company_name');
    });

    it('modifie sans justificatif les autres champs d\'un dossier validé', function () {
        $manager = cgaAgent();
        $member = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'ncc' => '1864699A', 'regime' => 'TEE']);

        inSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", memberForm(['company_name' => $member->company_name, 'main_activity' => 'Quincaillerie', 'employees_count' => 4]))
            ->assertOk()->assertJsonPath('data.main_activity', 'Quincaillerie')->assertJsonPath('data.employees_count', 4);
    });

    it('refuse un NCC déjà ouvert dans le centre, un dossier radié, et un chiffre d\'affaires venu de Compta Flow', function () {
        $manager = cgaAgent();
        $member = Member::factory()->create(['organization_id' => $manager->organization_id]);
        Member::factory()->create(['organization_id' => $manager->organization_id, 'ncc' => '9999999Z']);
        $radiated = Member::factory()->active()->create(['organization_id' => $manager->organization_id, 'status' => MemberStatus::Radiated]);
        inCentre($manager, function () use ($member): void {
            $revenue = new MemberRevenue;
            $revenue->member_id = $member->id;
            $revenue->year = 2025;
            $revenue->amount = 1;
            $revenue->setAttribute('kind', 'actual');
            $revenue->source = RevenueSource::ComptaFlow;
            $revenue->save();
        });

        inSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", memberForm(['ncc' => '9999999Z']))->assertStatus(409)->assertJsonPath('code', 'ncc_already_registered');
        inSpace($manager)->putJson("/api/v1/cga/members/{$radiated->id}", memberForm())->assertStatus(409)->assertJsonPath('code', 'member_closed');
        inSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", memberForm(['ncc' => $member->ncc, 'reference_revenue' => ['year' => 2025, 'amount' => 5, 'kind' => 'actual']]))
            ->assertStatus(409)->assertJsonPath('code', 'source_locked');
    });

    it('RG-ADH-05 refuse de changer le NCC d\'un dossier du CGA pour celui d\'un adhérent d\'un autre CGA', function () {
        $manager = cgaAgent();
        $member = Member::factory()->create(['organization_id' => $manager->organization_id]);
        Member::factory()->active()->create(['ncc' => '5555555K']);

        inSpace($manager)->putJson("/api/v1/cga/members/{$member->id}", memberForm(['ncc' => '5555555K']))->assertStatus(409)->assertJsonPath('code', 'membership_exclusive');
    });
});

describe('suppression d\'un brouillon (RG-ADH-01)', function () {
    it('RG-ADH-01 supprime un brouillon, jamais un dossier validé', function () {
        $manager = cgaAgent();
        $draft = Member::factory()->create(['organization_id' => $manager->organization_id]);
        $active = Member::factory()->active()->create(['organization_id' => $manager->organization_id]);

        inSpace($manager)->deleteJson("/api/v1/cga/members/{$draft->id}")->assertNoContent();
        inSpace($manager)->deleteJson("/api/v1/cga/members/{$active->id}")->assertStatus(409)->assertJsonPath('code', 'member_not_draft');

        expect(inCentre($manager, fn () => Member::query()->find($draft->id)))->toBeNull()
            ->and(inCentre($manager, fn () => Member::withTrashed()->find($draft->id)?->deleted_at))->not->toBeNull();
        inSpace($manager)->getJson("/api/v1/cga/members/{$draft->id}")->assertNotFound();
    });
});

describe('liste et droits', function () {
    it('liste les dossiers du portefeuille CGA par défaut, avec recherche et filtres', function () {
        $manager = cgaAgent();
        $org = $manager->organization_id;
        Member::factory()->create(['organization_id' => $org, 'company_name' => 'BOULANGERIE ADJOUA', 'ncc' => '1000001A', 'legacy_number' => 'DCK-OLD-7']);
        Member::factory()->active()->create(['organization_id' => $org, 'company_name' => 'QUINCAILLERIE BAMBA', 'regime' => 'RME']);
        Member::factory()->cabinet()->create(['organization_id' => $org, 'company_name' => 'CABINET CLIENT']);
        Member::factory()->create(['company_name' => 'AILLEURS']);

        $names = fn (string $query = ''): array => array_column((array) inSpace($manager)->getJson('/api/v1/cga/members'.$query)->assertOk()->json('data'), 'company_name');

        expect($names())->toBe(['BOULANGERIE ADJOUA', 'QUINCAILLERIE BAMBA'])
            ->and($names('?filter[portfolio]=cabinet'))->toBe(['CABINET CLIENT'])
            ->and($names('?filter[search]=adjoua'))->toBe(['BOULANGERIE ADJOUA'])
            ->and($names('?filter[search]=1000001 a'))->toBe(['BOULANGERIE ADJOUA'])
            ->and($names('?filter[search]=old-7'))->toBe(['BOULANGERIE ADJOUA'])
            ->and($names('?filter[status]=active&filter[regime]=RME'))->toBe(['QUINCAILLERIE BAMBA'])
            ->and($names('?sort=-company_name'))->toBe(['QUINCAILLERIE BAMBA', 'BOULANGERIE ADJOUA']);
        inSpace($manager)->getJson('/api/v1/cga/members?filter[regime]=XYZ')->assertUnprocessable();
    });

    it('liste les deux portefeuilles avec « Tous », et plusieurs statuts à la fois (onglet « Radiés / clos »)', function () {
        $manager = cgaAgent();
        $org = $manager->organization_id;
        Member::factory()->active()->create(['organization_id' => $org, 'company_name' => 'ACTIF CGA']);
        Member::factory()->active()->create(['organization_id' => $org, 'company_name' => 'RADIÉ CGA', 'status' => MemberStatus::Radiated]);
        Member::factory()->cabinet()->create(['organization_id' => $org, 'company_name' => 'CLOS CABINET', 'status' => MemberStatus::Closed]);
        Member::factory()->cabinet()->create(['organization_id' => $org, 'company_name' => 'KOUADIO, FILS']);

        $names = fn (string $query): array => array_column((array) inSpace($manager)->getJson('/api/v1/cga/members'.$query)->assertOk()->json('data'), 'company_name');

        expect($names('?filter[portfolio]=all'))->toBe(['ACTIF CGA', 'CLOS CABINET', 'KOUADIO, FILS', 'RADIÉ CGA'])
            ->and($names('?filter[portfolio]=all&filter[status]=radiated,closed'))->toBe(['CLOS CABINET', 'RADIÉ CGA'])
            ->and($names('?filter[status]=radiated,closed'))->toBe(['RADIÉ CGA'])
            // Une virgule de la recherche reste dans le texte cherché.
            ->and($names('?filter[portfolio]=all&filter[search]=kouadio, fils'))->toBe(['KOUADIO, FILS']);

        foreach (['?filter[status]=radiated,inconnu', '?filter[status]=,', '?filter[status][]=active', '?filter[portfolio]=tous'] as $query) {
            inSpace($manager)->getJson('/api/v1/cga/members'.$query)->assertUnprocessable();
        }
    });

    it('applique la matrice des droits du centre', function (Role $role, bool $view, bool $create, bool $update, bool $cabinet) {
        $agent = cgaAgent($role);
        $member = Member::factory()->create(['organization_id' => $agent->organization_id]);
        $cabinetMember = Member::factory()->cabinet()->create(['organization_id' => $agent->organization_id]);
        $status = fn (bool $allowed, int $ok): int => $allowed ? $ok : 403;

        inSpace($agent)->getJson('/api/v1/cga/members')->assertStatus($status($view, 200));
        inSpace($agent)->getJson("/api/v1/cga/members/{$member->id}")->assertStatus($status($view, 200));
        inSpace($agent)->getJson('/api/v1/cga/members?filter[portfolio]=cabinet')->assertStatus($status($view && $cabinet, 200));
        inSpace($agent)->getJson("/api/v1/cga/members/{$cabinetMember->id}")->assertStatus($status($view && $cabinet, 200));
        inSpace($agent)->postJson('/api/v1/cga/members', memberForm())->assertStatus($status($create, 201));
        inSpace($agent)->putJson("/api/v1/cga/members/{$member->id}", memberForm(['ncc' => $member->ncc]))->assertStatus($status($update, 200));
    })->with([
        'gérant' => [Role::CgaManager, true, true, true, true],
        'comptable' => [Role::CgaAccountant, true, true, true, true],
        'secrétaire' => [Role::CgaSecretary, true, true, false, true],
        'chargé de facturation' => [Role::CgaBillingOfficer, true, false, false, false],
        'formateur' => [Role::CgaTrainer, true, false, false, false],
    ]);

    it('refuse les dossiers hors d\'un CGA et ceux d\'un autre CGA', function () {
        $manager = cgaAgent();
        $elsewhere = Member::factory()->create();

        inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson('/api/v1/cga/members')->assertForbidden();
        inSpace($manager)->getJson("/api/v1/cga/members/{$elsewhere->id}")->assertNotFound();
        inSpace($manager)->deleteJson("/api/v1/cga/members/{$elsewhere->id}")->assertNotFound();
    });
});
