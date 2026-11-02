<?php

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Actions\SendReportReminders;
use App\Domain\Reports\Enums\CgaResourceCategory;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Events\ReportLate;
use App\Domain\Reports\Events\ReportLegallyLate;
use App\Domain\Reports\Events\ReportReminderDue;
use App\Domain\Reports\Models\CgaPerson;
use App\Domain\Reports\Models\CgaResource;
use App\Domain\Reports\Models\QuarterlyReport;
use App\Domain\Reports\Models\QuarterlyReportVersion;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Transmission, accusé, correction et échéances du rapport trimestriel (06-IMPLEMENTATION-PLAN étape
 * 5.5, F-RPT-04 à 07, RG-RPT-04 à 07, RG-RPT-08 pour l'instantané, F-ORG-07) ; vue du CNCGA sans aucune
 * donnée nominative.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $this->travelTo('2026-10-20 10:00:00');
    $cga = Organization::factory()->profileComplete()->create(['numbering_abbreviation' => 'TRS', 'said' => 'SAID', 'history_text' => 'Historique.', 'previous_year_revenue' => 1_000_000]);
    $this->manager = Membership::factory()->role(Role::CgaManager)->create(['organization_id' => $cga->id]);
    $this->member = Member::factory()->active()->create(['organization_id' => $cga->id, 'company_name' => 'ADHERENT CONFIDENTIEL']);
    $this->report = transmissionReport($this->manager);
});

function transmissionReport(Membership $manager): QuarterlyReport
{
    $director = CgaPerson::factory()->create(['organization_id' => $manager->organization_id]);
    foreach (CgaResourceCategory::cases() as $category) {
        CgaResource::factory()->create(['organization_id' => $manager->organization_id, 'category' => $category, 'quantity' => 1]);
    }
    $id = inSpace($manager)->postJson('/api/v1/cga/quarterly-reports', ['year' => 2026, 'quarter' => 3])->assertCreated()->json('data.id');
    $content = [];
    foreach ((array) config('reports.content') as $section) {
        foreach ($section as $key => $definition) {
            $content[$key] = $definition['type'] === 'integer' ? 0 : 'Néant';
        }
    }
    inSpace($manager)->patchJson("/api/v1/cga/quarterly-reports/{$id}", [
        'content' => $content, 'qualitative_analysis' => ['synthesis' => 'Stable.'], 'report_date' => '2026-10-20', 'regional_directorate_label' => 'DR Sud',
        'people_taxes' => [['person_id' => $director->id, 'its' => 1, 'igr' => 1, 'cnps' => 1]],
    ])->assertOk();

    return Tenancy::bypass(static fn () => QuarterlyReport::query()->findOrFail($id), 'Test.');
}

function reportAction(QuarterlyReport $report, string $action): string
{
    return "/api/v1/cga/quarterly-reports/{$report->id}/{$action}";
}

function freshReport(QuarterlyReport $report): QuarterlyReport
{
    return Tenancy::bypass(static fn () => QuarterlyReport::query()->findOrFail($report->id), 'Test.');
}

function transmitPayload(): array
{
    return ['transmitted_on' => '2026-10-20', 'mode' => 'physical', 'reference' => 'DEP-SPCGA-0042'];
}

describe('transmission, accusé, correction (F-RPT-04 à 06)', function () {
    it('RG-RPT-04 · transmet la version générée : bordereau, horodatage, synthèse agrégée pour le CNCGA', function () {
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertStatus(409)->assertJsonPath('code', 'report_not_generated');
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);

        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), [...transmitPayload(), 'transmitted_on' => '2026-10-21', 'mode' => 'fax'])
            ->assertUnprocessable()->assertJsonValidationErrors(['transmitted_on', 'mode']);
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'transmitted')
            ->assertJsonPath('data.transmission.mode_label', 'Dépôt physique')
            ->assertJsonPath('data.transmission.reference', 'DEP-SPCGA-0042')
            ->assertJsonPath('data.can.acknowledge', true)
            ->assertJsonPath('data.can.update', false);

        $report = freshReport($this->report);
        $version = Tenancy::bypass(static fn () => QuarterlyReportVersion::query()->with('slipDocument')->where('report_id', $report->id)->sole(), 'Test.');
        expect($report->summary['totals']['active_total'])->toBe(1)
            ->and($report->summary['version'])->toBe(1)
            ->and(json_encode($report->summary))->not->toContain('ADHERENT CONFIDENTIEL')->not->toContain($this->member->id)
            ->and($version->transmitted_at)->not->toBeNull()
            ->and($version->slipDocument)->status->toBe(DocumentStatus::Valid)->number->toBe('TRS-RT-2026-T3');
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertStatus(409)->assertJsonPath('code', 'report_not_generated');
        inSpace($this->manager)->getJson(reportAction($this->report, 'versions'))->assertOk()->assertJsonPath('data.0.documents.slip.status', 'valid');
    });

    it('enregistre l\'accusé de réception : date, référence, fichier scanné', function () {
        inSpace($this->manager)->post(reportAction($this->report, 'acknowledge'), ['acknowledged_on' => '2026-10-20', 'reference' => 'AR-1', 'file' => UploadedFile::fake()->createWithContent('ar.pdf', "%PDF-1.4\n%%EOF")])
            ->assertStatus(409)->assertJsonPath('code', 'report_not_transmitted');
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertOk();

        inSpace($this->manager)->postJson(reportAction($this->report, 'acknowledge'), ['acknowledged_on' => '2026-10-20'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reference', 'file']);
        inSpace($this->manager)->post(reportAction($this->report, 'acknowledge'), ['acknowledged_on' => '2026-10-20', 'reference' => 'AR-SPCGA-7', 'file' => UploadedFile::fake()->createWithContent('accuse.pdf', "%PDF-1.4\n%%EOF")])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged')
            ->assertJsonPath('data.acknowledgement.reference', 'AR-SPCGA-7')
            ->assertJsonPath('data.acknowledgement.file.name', 'accuse.pdf')
            ->assertJsonPath('data.can.correct', true);
    });

    it('RG-RPT-05 · RG-RPT-08 · corrige par une V2 avec motif ; la V1 garde son instantané et sa règle « augmentée »', function () {
        inSpace($this->manager)->postJson(reportAction($this->report, 'correct'), ['reason' => 'Erreur de CDI'])->assertStatus(409)->assertJsonPath('code', 'report_not_transmitted');
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertOk();

        inSpace($this->manager)->postJson(reportAction($this->report, 'correct'), ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        inSpace($this->manager)->postJson(reportAction($this->report, 'correct'), ['reason' => 'Erreur de CDI sur deux dossiers'])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.current_version', 2)->assertJsonPath('data.can.update', true);

        app(SettingsService::class)->set('report.increase_rule', ['basis' => 'revenue', 'min_increase_pct' => 10, 'amount' => 'gross']);
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202)->assertJsonPath('data.current_version', 2);

        $versions = Tenancy::bypass(fn () => QuarterlyReportVersion::query()->where('report_id', $this->report->id)->orderBy('version')->get(), 'Test.');
        expect($versions)->toHaveCount(2)
            ->and($versions[0]->snapshot['increase_rule'])->toEqual(['basis' => 'annual_due', 'min_increase_pct' => 0, 'amount' => 'net'])
            ->and($versions[0]->transmitted_at)->not->toBeNull()
            ->and($versions[1]->reason)->toBe('Erreur de CDI sur deux dossiers')
            ->and($versions[1]->snapshot['increase_rule'])->toEqual(['basis' => 'revenue', 'min_increase_pct' => 10, 'amount' => 'gross'])
            ->and($versions[1]->transmitted_at)->toBeNull();
        // Le CNCGA garde la synthèse de la V1 jusqu'à la transmission de la V2.
        expect(freshReport($this->report)->summary['version'])->toBe(1);
    });

    it('transmis, accusé et corrigé avec reports.transmit seulement', function (Role $role, int $status) {
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);
        $agent = Membership::factory()->role($role)->create(['organization_id' => $this->manager->organization_id]);

        inSpace($agent)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertStatus($status);
    })->with([
        'gérant' => [Role::CgaManager, 200],
        'expert-comptable' => [Role::CgaAccountantExpert, 200],
        'comptable' => [Role::CgaAccountant, 403],
        'secrétaire' => [Role::CgaSecretary, 403],
    ]);
});

describe('vue du réseau (RG-RPT-04, RG-RPT-07)', function () {
    it('RG-RPT-04 · état de chaque CGA pour le trimestre, synthèse agrégée seulement : aucune route CNCGA ne renvoie un nom d\'adhérent', function () {
        $other = Organization::factory()->create(['name' => 'CGA SANS RAPPORT']);
        $president = Membership::factory()->role(Role::CncgaPresident)->create();
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertOk();
        $this->travelTo('2026-11-20 10:00:00');
        $this->flushSession();
        auth()->forgetGuards();

        $grid = inSpace($president)->getJson('/api/v1/network/quarterly-reports?year=2026&quarter=3')->assertOk()
            ->assertJsonPath('meta.deadline', '2026-10-31')
            ->assertJsonPath('meta.legal_deadline', '2026-11-15');
        $rows = collect($grid->json('data'))->keyBy('cga.id');
        expect($rows[$this->manager->organization_id])->toMatchArray(['status' => 'transmitted', 'version' => 1])
            ->and($rows[$other->id])->toMatchArray(['status' => 'legally_late', 'status_label' => 'Hors délai réglementaire'])
            ->and($grid->getContent())->not->toContain('ADHERENT CONFIDENTIEL');

        $summary = inSpace($president)->getJson("/api/v1/network/quarterly-reports/{$this->manager->organization_id}/summary?year=2026&quarter=3")
            ->assertOk()->assertJsonPath('data.summary.totals.active_total', 1);
        expect($summary->getContent())->not->toContain('ADHERENT CONFIDENTIEL')->not->toContain($this->member->id)->not->toContain($this->member->ncc);

        inSpace($president)->getJson("/api/v1/network/quarterly-reports/{$other->id}/summary?year=2026&quarter=3")->assertNotFound();
        inSpace($president)->getJson("/api/v1/network/quarterly-reports/{$president->organization_id}/summary?year=2026&quarter=3")->assertNotFound();
        inSpace($president)->getJson('/api/v1/network/quarterly-reports?year=2026&quarter=7')->assertUnprocessable()->assertJsonValidationErrors('quarter');
        inSpace($this->manager)->getJson('/api/v1/network/quarterly-reports?year=2026&quarter=3')->assertForbidden();
    });

    it('RG-RPT-07 · en retard après J, hors délai réglementaire après J+15', function () {
        $president = Membership::factory()->role(Role::CncgaManager)->create();
        $status = fn (): string => collect(inSpace($president)->getJson('/api/v1/network/quarterly-reports?year=2026&quarter=3')->json('data'))
            ->firstWhere('cga.id', $this->manager->organization_id)['status'];

        expect($status())->toBe('not_transmitted');
        $this->travelTo('2026-11-02 10:00:00');
        $this->flushSession();
        auth()->forgetGuards();
        expect($status())->toBe('late');
    });
});

describe('échéances (RG-RPT-06, RG-RPT-07)', function () {
    it('RG-RPT-06 · reminders:reports relance à J-15, J-5 et J+5 sans doublon, signale le retard puis le hors délai', function () {
        Event::fake([ReportReminderDue::class, ReportLate::class, ReportLegallyLate::class]);
        $quiet = Organization::factory()->create();

        $this->travelTo('2026-10-16 07:15:00');
        $this->artisan('reminders:reports')->assertSuccessful()->expectsOutputToContain('2 relance(s)');
        $this->artisan('reminders:reports')->assertSuccessful()->expectsOutputToContain('0 relance(s)');
        Event::assertDispatched(ReportReminderDue::class, fn ($event) => $event->organizationId === $quiet->id && $event->offset === -15 && $event->quarter === 3);
        expect(freshReport($this->report)->reminders_sent)->toBe([-15]);

        $this->travelTo('2026-11-01 07:15:00');
        $this->artisan('reminders:reports')->assertSuccessful()->expectsOutputToContain('2 rapport(s) en retard');
        expect(freshReport($this->report)->is_late)->toBeTrue();

        $this->travelTo('2026-11-05 07:15:00');
        $this->artisan('reminders:reports')->assertSuccessful()->expectsOutputToContain('2 relance(s), 0 rapport(s) en retard');

        $this->travelTo('2026-11-16 07:15:00');
        $this->artisan('reminders:reports')->assertSuccessful()->expectsOutputToContain('2 hors délai réglementaire');
        Event::assertDispatched(ReportLegallyLate::class, fn ($event) => $event->organizationId === $this->manager->organization_id);
        expect(freshReport($this->report)->is_legally_late)->toBeTrue();
        expect(collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'reminders:reports'))->expression)->toBe('15 7 * * *');
    });

    it('un rapport transmis n\'est plus relancé ; une erreur est journalisée puis relancée', function () {
        Event::fake([ReportReminderDue::class]);
        inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);
        inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertOk();
        $this->travelTo('2026-10-26 07:15:00');

        $this->artisan('reminders:reports')->assertSuccessful()->expectsOutputToContain('0 relance(s)');
        Event::assertNotDispatched(ReportReminderDue::class);

        Log::spy();
        $this->mock(SendReportReminders::class)->shouldReceive('execute')->andThrow(new RuntimeException('panne'));
        expect(fn () => $this->artisan('reminders:reports')->run())->toThrow(RuntimeException::class, 'panne');
        Log::shouldHaveReceived('error')->once();
    });
});

describe('perte d\'agrément (F-ORG-07)', function () {
    it('liste les dossiers du portefeuille CGA à radier, sans radier aucun dossier', function () {
        inSpace($this->manager)->getJson('/api/v1/cga/agreement-loss/members')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.agreement_withdrawn', false);

        Member::factory()->active()->cabinet()->create(['organization_id' => $this->manager->organization_id, 'cga_contract_signed_on' => null]);
        Tenancy::bypass(fn () => $this->manager->organization->forceFill(['agreement_status' => AgreementStatus::Withdrawn, 'agreement_withdrawn_at' => '2026-10-15'])->save(), 'Test.');

        inSpace($this->manager)->getJson('/api/v1/cga/agreement-loss/members')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company_name', 'ADHERENT CONFIDENTIEL')
            ->assertJsonPath('data.0.radiation', null)
            ->assertJsonPath('meta.proposed_reason.code', 'agreement_loss');
        expect(Tenancy::bypass(fn () => Member::query()->whereKey($this->member->id)->value('status'), 'Test.'))->toBe(MemberStatus::Active);

        $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $this->manager->organization_id]);
        inSpace($accountant)->getJson('/api/v1/cga/agreement-loss/members')->assertForbidden();
    });
});

it('le bordereau et les documents du rapport ne sont jamais visibles de l\'adhérent', function () {
    inSpace($this->manager)->postJson(reportAction($this->report, 'generate'))->assertStatus(202);
    inSpace($this->manager)->postJson(reportAction($this->report, 'transmit'), transmitPayload())->assertOk();

    expect(Tenancy::bypass(static fn () => GeneratedDocument::query()->where('visible_to_member', true)->whereIn('type', ['quarterly_report_docx', 'quarterly_report_xlsx', 'report_transmission_slip'])->count(), 'Test.'))->toBe(0)
        ->and(freshReport($this->report)->status)->toBe(ReportStatus::Transmitted);
});
