<?php

use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Reports\Enums\CgaPersonKind;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;

/*
 * Rapport trimestriel du centre (06-IMPLEMENTATION-PLAN étape 5.3, F-RPT-02, RG-RPT-02, RG-RPT-06,
 * RG-OBL-12) : préparation du trimestre en cours ou précédent, échéances, contenu enregistré champ par
 * champ, complétude par section, états calculés en lecture, droits et cloisonnement.
 */

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
});

function reportManager(): Membership
{
    $manager = Membership::factory()->role(Role::CgaManager)->create();
    $directorate = RegionalDirectorate::factory()->create(['name' => 'DR Abidjan Nord']);
    Tenancy::bypass(static fn () => $manager->organization->forceFill(['regional_directorate_id' => $directorate->id])->save(), 'Test.');

    return $manager;
}

function reportUrl(QuarterlyReport|string $report = ''): string
{
    return '/api/v1/cga/quarterly-reports'.($report === '' ? '' : '/'.($report instanceof QuarterlyReport ? $report->id : $report));
}

function newReport(Membership $agent, int $quarter = 3): QuarterlyReport
{
    $id = inSpace($agent)->postJson(reportUrl(), ['year' => 2026, 'quarter' => $quarter])->assertCreated()->json('data.id');

    return Tenancy::bypass(static fn () => QuarterlyReport::query()->findOrFail($id), 'Test.');
}

/**
 * Toutes les sections remplies : fiche technique du centre, un dirigeant et ses impôts, les moyens de
 * chaque catégorie, sections 4 et 6, synthèse.
 */
function completeReport(Membership $agent, QuarterlyReport $report): void
{
    $cga = $agent->organization;
    Tenancy::bypass(static fn () => $cga->forceFill([
        'ncc' => '1234567A', 'agreement_number' => '301/SEPMBPE/DGI', 'address' => 'Plateau', 'said' => 'SAID Plateau',
        'history_text' => 'Créé en 2004.', 'previous_year_revenue' => 48_000_000,
    ])->save(), 'Test.');
    $director = CgaPerson::factory()->create(['organization_id' => $cga->id]);
    foreach (CgaResourceCategory::cases() as $category) {
        if ($category !== CgaResourceCategory::Other) {
            CgaResource::factory()->create(['organization_id' => $cga->id, 'category' => $category, 'quantity' => 1]);
        }
    }
    $content = [];
    foreach ((array) config('reports.content') as $section) {
        foreach ($section as $key => $definition) {
            $content[$key] = $definition['type'] === 'integer' ? 0 : 'Néant';
        }
    }

    inSpace($agent)->patchJson(reportUrl($report), [
        'content' => $content,
        'people_taxes' => [['person_id' => $director->id, 'its' => 120_000, 'igr' => 45_000, 'cnps' => 60_000]],
        'qualitative_analysis' => ['synthesis' => 'Trimestre stable.'],
        'report_date' => '2026-10-20',
    ])->assertOk();
}

describe('préparation (F-RPT-02, RG-RPT-06)', function () {
    it('RG-RPT-06 · prépare le trimestre précédent : date limite fin du mois suivant, échéance réglementaire J+15', function () {
        $manager = reportManager();

        inSpace($manager)->postJson(reportUrl(), ['year' => 2026, 'quarter' => 3])
            ->assertCreated()
            ->assertJsonPath('data.label', 'T3 2026')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.deadline', '2026-10-31')
            ->assertJsonPath('data.legal_deadline', '2026-11-15')
            ->assertJsonPath('data.period', ['start' => '2026-07-01', 'end' => '2026-09-30'])
            ->assertJsonPath('data.regional_directorate_label', 'DR Abidjan Nord')
            ->assertJsonPath('data.can.generate', false);
        inSpace($manager)->postJson(reportUrl(), ['year' => 2026, 'quarter' => 3])->assertStatus(409)->assertJsonPath('code', 'report_exists');
        inSpace($manager)->postJson(reportUrl(), ['year' => 2026, 'quarter' => 4])->assertCreated()->assertJsonPath('data.deadline', '2027-01-31');
        inSpace($manager)->postJson(reportUrl(), ['year' => 2026, 'quarter' => 2])->assertUnprocessable()->assertJsonValidationErrors('quarter');
        inSpace($manager)->postJson(reportUrl(), ['year' => 2026, 'quarter' => 5])->assertUnprocessable()->assertJsonValidationErrors('quarter');

        inSpace($manager)->getJson(reportUrl())->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.creatable', [])->assertJsonPath('data.0.quarter', 4);
    });

    it('RG-RPT-06 · la règle d\'échéance du réseau est paramétrable', function () {
        app(SettingsService::class)->set('report.deadline_rule', ['months_after' => 1, 'day' => 15]);
        app(SettingsService::class)->set('report.legal_offset_days', 10);
        $manager = reportManager();

        inSpace($manager)->getJson(reportUrl())->assertOk()->assertJsonPath('meta.creatable', [['year' => 2026, 'quarter' => 4], ['year' => 2026, 'quarter' => 3]]);
        expect(newReport($manager))->deadline->toDateString()->toBe('2026-10-15')->legal_deadline->toDateString()->toBe('2026-10-25');
    });

    it('RG-OBL-12 · propose les SMT et bilans établis et transmis de la campagne de clôture de l\'exercice précédent', function () {
        $manager = reportManager();
        $campaign = ClosingCampaign::factory()->create(['organization_id' => $manager->organization_id, 'fiscal_year' => 2025]);
        $done = ['step_pieces_at' => '2026-01-10', 'step_regularisation_at' => '2026-02-10', 'step_finalisation_at' => '2026-03-10'];
        ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, ...$done, 'step_filing_at' => '2026-05-10']);
        ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, ...$done]);
        ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, ...$done, 'deliverable' => 'financial_statements']);
        ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, ...$done, 'portfolio' => 'cabinet']);

        $report = newReport($manager);
        expect($report->content)->toBe(['smt_established' => 2, 'smt_transmitted' => 1, 'balance_sheets_established' => 1, 'balance_sheets_transmitted' => 0]);

        inSpace($manager)->patchJson(reportUrl($report), ['content' => ['smt_established' => 5]])->assertOk()->assertJsonPath('data.content.smt_established', 5);
        inSpace($manager)->getJson(reportUrl($report))->assertOk()->assertJsonPath('data.closing_proposals.smt_established', 2)->assertJsonPath('data.closing_proposals.fiscal_year', 2025);
    });
});

describe('contenu et complétude (RG-RPT-02)', function () {
    it('RG-RPT-02 · complétude par section : champs requis renseignés / requis ; « Néant » vaut réponse', function () {
        $manager = reportManager();
        $report = newReport($manager);

        inSpace($manager)->getJson(reportUrl($report))->assertOk()
            ->assertJsonPath('data.completeness.section_2', 0)
            ->assertJsonPath('data.completeness.section_3', 0)
            ->assertJsonPath('data.completeness.section_4', 0)
            ->assertJsonPath('data.completeness.section_5', 100)
            ->assertJsonPath('data.completeness.section_6', 0);

        inSpace($manager)->patchJson(reportUrl($report), ['content' => ['trainings' => 'Néant', 'tools' => '  ', 'smt_established' => 0]])
            ->assertOk()
            ->assertJsonPath('data.content.tools', null)
            // 2 champs requis sur 13 : 15 %.
            ->assertJsonPath('data.completeness.section_4', 15);

        completeReport($manager, $report);
        inSpace($manager)->getJson(reportUrl($report))->assertOk()
            ->assertJsonPath('data.completeness', ['section_1' => 100, 'section_2' => 100, 'section_3' => 100, 'section_4' => 100, 'section_5' => 100, 'section_6' => 100])
            ->assertJsonPath('data.completeness_overall', 100)
            ->assertJsonPath('data.can.generate', true)
            ->assertJsonPath('data.directors.0.function_in_cga', 'Gérant');

        // Un dirigeant de plus sans ses impôts : la section 2 n'est plus complète (4 champs sur 7).
        CgaPerson::factory()->create(['organization_id' => $manager->organization_id]);
        inSpace($manager)->patchJson(reportUrl($report), ['report_date' => '2026-10-21'])->assertOk()->assertJsonPath('data.completeness.section_2', 57)->assertJsonPath('data.can.generate', false);
    });

    it('n\'accepte que les champs déclarés, des nombres positifs et les dirigeants actifs du centre', function () {
        $manager = reportManager();
        $report = newReport($manager);
        $employee = CgaPerson::factory()->kind(CgaPersonKind::Employee)->create(['organization_id' => $manager->organization_id]);
        $former = CgaPerson::factory()->create(['organization_id' => $manager->organization_id, 'active' => false, 'end_date' => '2026-06-30']);
        $foreign = CgaPerson::factory()->create();

        inSpace($manager)->patchJson(reportUrl($report), ['content' => ['inconnu' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('content');
        inSpace($manager)->patchJson(reportUrl($report), ['content' => ['smt_established' => -1, 'trainings' => str_repeat('a', 5001)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['content.smt_established', 'content.trainings']);
        inSpace($manager)->patchJson(reportUrl($report), ['qualitative_analysis' => ['avis' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('qualitative_analysis');
        inSpace($manager)->patchJson(reportUrl($report), ['people_taxes' => [
            ['person_id' => $employee->id, 'its' => 1], ['person_id' => $former->id, 'its' => 1], ['person_id' => $foreign->id, 'its' => -5],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['people_taxes.0.person_id', 'people_taxes.1.person_id', 'people_taxes.2.person_id', 'people_taxes.2.its']);
        inSpace($manager)->patchJson(reportUrl($report), ['report_date' => '20/10/2026', 'status' => 'transmitted'])->assertUnprocessable()->assertJsonValidationErrors('report_date');

        expect(Tenancy::bypass(static fn () => QuarterlyReport::query()->findOrFail($report->id)->status, 'Test.'))->toBe(ReportStatus::Draft);
    });

    it('un rapport généré modifié repasse en brouillon ; un rapport transmis ne se modifie plus', function () {
        $manager = reportManager();
        $report = newReport($manager);
        Tenancy::bypass(static fn () => $report->forceFill(['status' => ReportStatus::Generated])->save(), 'Test.');

        inSpace($manager)->patchJson(reportUrl($report), ['content' => ['trainings' => 'Deux sessions']])->assertOk()->assertJsonPath('data.status', 'draft');

        Tenancy::bypass(static fn () => $report->forceFill(['status' => ReportStatus::Transmitted, 'transmitted_at' => now(), 'transmission_mode' => 'email'])->save(), 'Test.');
        inSpace($manager)->patchJson(reportUrl($report), ['content' => ['trainings' => 'Trois']])->assertStatus(409)->assertJsonPath('code', 'report_not_editable');
    });

    it('RG-RPT-01 · montre les états du portefeuille calculés en lecture', function () {
        $manager = reportManager();
        $report = newReport($manager);

        inSpace($manager)->getJson(reportUrl($report).'/states')->assertOk()
            ->assertJsonPath('data.period.end', '2026-09-30')
            ->assertJsonPath('data.increase_rule', ['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net'])
            ->assertJsonPath('data.sheets.summary.totals.active_total', 0);
    });
});

describe('droits et cloisonnement', function () {
    it('préparé avec reports.prepare ; lu aussi avec reports.transmit', function (Role $role, int $read, int $write) {
        $manager = reportManager();
        $report = newReport($manager);
        $agent = Membership::factory()->role($role)->create(['organization_id' => $manager->organization_id]);

        inSpace($agent)->getJson(reportUrl($report))->assertStatus($read);
        inSpace($agent)->patchJson(reportUrl($report), ['content' => ['trainings' => 'Néant']])->assertStatus($write);
        inSpace($agent)->postJson(reportUrl(), ['year' => 2026, 'quarter' => 4])->assertStatus($write === 200 ? 201 : $write);
    })->with([
        'gérant' => [Role::CgaManager, 200, 200],
        'expert-comptable' => [Role::CgaAccountantExpert, 200, 200],
        'comptable' => [Role::CgaAccountant, 200, 200],
        'secrétaire' => [Role::CgaSecretary, 200, 200],
        'chargé des honoraires' => [Role::CgaBillingOfficer, 403, 403],
        'formateur' => [Role::CgaTrainer, 403, 403],
    ]);

    it('RG-SEC-02 · le rapport d\'un autre CGA répond 404 ; ni le CNCGA ni l\'adhérent n\'y accèdent par ces routes', function () {
        $manager = reportManager();
        $foreign = QuarterlyReport::factory()->create();

        inSpace($manager)->getJson(reportUrl($foreign))->assertNotFound();
        inSpace($manager)->patchJson(reportUrl($foreign), ['content' => ['trainings' => 'x']])->assertNotFound();
        inSpace($manager)->getJson(reportUrl($foreign).'/states')->assertNotFound();
        inSpace(Membership::factory()->role(Role::CncgaPresident)->create())->getJson(reportUrl())->assertForbidden();
        inSpace(Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $manager->organization_id]))->getJson(reportUrl())->assertForbidden();
        expect(Organization::query()->count())->toBeGreaterThan(1);
    });
});
