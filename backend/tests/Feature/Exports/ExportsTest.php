<?php

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Exports\Actions\RunExport;
use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Exporters\MembersExporter;
use App\Domain\Exports\Jobs\GenerateExport;
use App\Domain\Exports\Models\Export;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TemplateSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/*
 * Exports Excel en tâche de fond et plaquette PDF annuelle (06-IMPLEMENTATION-PLAN étape 8.3, PRD M17 F-EXP-01,
 * RG-SEC-06, M15 F-STA-03) : listes de chaque espace, périmètre de la personne qui exporte, horodatage, période et
 * filtres, suivi et téléchargement, notification, journal d'audit ; plaquette statistique produite par la chaîne
 * documentaire. Décision 0017.
 */

function exportSpace(Membership $membership): TestCase
{
    return inSpace($membership)->withSession([AuthSession::LAST_ACTIVITY_KEY => now()->getTimestamp()]);
}

/**
 * Feuilles du fichier d'un export prêt : nom => lignes (en-tête compris).
 *
 * @return array<string, list<list<mixed>>>
 */
function exportSheets(string $exportId): array
{
    $export = Tenancy::bypass(static fn (): Export => Export::query()->with('file')->findOrFail($exportId), 'Test : lecture d\'un export.');
    expect($export->status)->toBe(ExportStatus::Done);
    $reader = new Reader;
    $reader->open(Storage::disk($export->file->disk)->path($export->file->path));
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    return $sheets;
}

function exportOf(TestCase $space, string $url, array $payload): string
{
    return (string) $space->postJson($url, $payload)->assertStatus(202)->json('data.id');
}

beforeEach(function () {
    Storage::fake();
    config(['queue.default' => 'sync']);
    $this->travelTo(CarbonImmutable::parse('2026-03-31 18:00:00'));
    $this->cncga = Organization::factory()->cncga()->profileComplete()->create(['numbering_abbreviation' => 'CNCG']);
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create(['organization_id' => $this->cncga->id]);
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $this->cga->id]);
});

describe('exports d\'un CGA (F-EXP-01, RG-SEC-06)', function () {
    beforeEach(function () {
        $referrer = Tenancy::bypass(fn () => BusinessReferrer::factory()->create(['organization_id' => $this->cga->id, 'label' => 'Accessoire 1']), 'Test.');
        $this->tee = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'ALPHA TEE', 'regime' => 'TEE', 'adhesion_date' => '2026-02-10', 'referrer_id' => $referrer->id]);
        $this->rme = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'BETA RME', 'regime' => 'RME', 'adhesion_date' => '2025-06-01']);
        $this->cabinet = Member::factory()->active()->cabinet()->create(['organization_id' => $this->cga->id, 'company_name' => 'GAMMA CABINET', 'cga_contract_signed_on' => null, 'client_number' => 'CAB-TST-00001', 'adhesion_date' => '2024-05-01']);
        Member::factory()->active()->create(['company_name' => 'AUTRE CENTRE']);
    });

    it('exporte en tâche de fond les adhérents du portefeuille CGA, horodatés, avec l\'apporteur pour qui a le droit', function () {
        $space = exportSpace($this->manager);
        $id = exportOf($space, '/api/v1/cga/exports', ['type' => 'members']);

        $space->getJson("/api/v1/cga/exports/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.type_label', 'Adhérents')
            ->assertJsonPath('data.rows_count', 2)
            ->assertJsonPath('data.file.name', 'adherents-20260331-1800.xlsx')
            ->assertJsonPath('data.file.url', fn (string $url): bool => str_contains($url, 'signature='));
        $sheets = exportSheets($id);
        expect(array_keys($sheets))->toBe(['Adhérents', 'Informations'])
            ->and($sheets['Adhérents'][0][3])->toBe('Raison sociale')
            ->and(end($sheets['Adhérents'][0]))->toBe('Apporteur')
            ->and(array_column(array_slice($sheets['Adhérents'], 1), 3))->toBe(['ALPHA TEE', 'BETA RME'])
            ->and(end($sheets['Adhérents'][1]))->toBe('Accessoire 1')
            ->and(array_column($sheets['Informations'], 1, 0))->toMatchArray([
                'Liste' => 'Adhérents', 'Exporté le' => '31/03/2026 18:00', 'Par' => $this->manager->user->fullName(),
                'Espace' => $this->cga->name, 'Période' => 'Toutes les périodes', 'Filtres' => 'Aucun', 'Lignes' => '2',
            ]);
        expect(DB::table('audit_logs')->where('event', 'export')->where('organization_id', $this->cga->id)->count())->toBe(1)
            ->and(json_encode(DatabaseNotification::query()->where('notifiable_id', $this->manager->user_id)->where('type', 'export_ready')->sole()->data, JSON_UNESCAPED_UNICODE))->toContain('Adhérents');
    });

    it('applique la période et les filtres ; le portefeuille Cabinet et l\'apporteur suivent les droits de la personne', function () {
        $id = exportOf(exportSpace($this->expert), '/api/v1/cga/exports', ['type' => 'members', 'period' => 'year', 'date' => '2026-03-01', 'filters' => ['portfolio' => 'all', 'regimes' => ['TEE', 'RME']]]);
        expect(array_column(array_slice(exportSheets($id)['Adhérents'], 1), 3))->toBe(['ALPHA TEE'])
            ->and(array_column(exportSheets($id)['Informations'], 1, 0)['Période'])->toBe('Du 01/01/2026 au 31/12/2026');

        $all = exportOf(exportSpace($this->expert), '/api/v1/cga/exports', ['type' => 'members', 'filters' => ['portfolio' => 'all']]);
        expect(array_column(array_slice(exportSheets($all)['Adhérents'], 1), 3))->toBe(['ALPHA TEE', 'BETA RME', 'GAMMA CABINET']);

        DB::table('role_permissions')->insert([
            ['organization_id' => $this->cga->id, 'role' => 'cga_accountant_expert', 'permission' => 'members.cabinet.view', 'granted' => false, 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->cga->id, 'role' => 'cga_accountant_expert', 'permission' => 'members.referrers.view', 'granted' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        cache()->flush();
        exportSpace($this->expert)->postJson('/api/v1/cga/exports', ['type' => 'members', 'filters' => ['portfolio' => 'cabinet']])->assertUnprocessable()->assertJsonValidationErrors('filters.portfolio');
        $limited = exportOf(exportSpace($this->expert), '/api/v1/cga/exports', ['type' => 'members']);
        expect(exportSheets($limited)['Adhérents'][0])->not->toContain('Apporteur');
    });

    it('refuse une liste d\'un autre espace, un filtre inconnu ou invalide, une période trop longue (422), sans exports.run (403)', function () {
        $space = exportSpace($this->manager);
        $space->postJson('/api/v1/cga/exports', ['type' => 'dues'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $space->postJson('/api/v1/cga/exports', ['type' => 'members', 'filters' => ['inconnu' => 1]])->assertUnprocessable()->assertJsonValidationErrors('filters');
        $space->postJson('/api/v1/cga/exports', ['type' => 'members', 'filters' => ['statuses' => ['perdu']]])->assertUnprocessable()->assertJsonValidationErrors('filters.statuses.0');
        $space->postJson('/api/v1/cga/exports', ['type' => 'members', 'period' => 'custom', 'from' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        config(['cga.statistics.max_period_days' => 10]);
        $space->postJson('/api/v1/cga/exports', ['type' => 'members', 'period' => 'month'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $accountant = Membership::factory()->role(Role::CgaAccountant)->create(['organization_id' => $this->cga->id]);
        exportSpace($accountant)->postJson('/api/v1/cga/exports', ['type' => 'members'])->assertForbidden();
        exportSpace($accountant)->getJson('/api/v1/cga/exports')->assertForbidden();
        $owner = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $this->tee->id]);
        exportSpace($owner)->postJson('/api/v1/cga/exports', ['type' => 'members'])->assertForbidden();
    });

    it('exporte les préoccupations reçues, les échéances et les inscrits aux formations du centre', function () {
        Concern::factory()->create(['organization_id' => $this->cga->id, 'member_id' => $this->tee->id, 'subject' => 'Question TVA', 'created_at' => '2026-03-02 09:00:00']);
        Concern::factory()->create(['organization_id' => $this->cga->id, 'member_id' => $this->cabinet->id, 'subject' => 'Question du Cabinet', 'created_at' => '2026-03-03 09:00:00']);
        $obligation = MemberObligation::factory()->create(['member_id' => $this->tee->id]);
        ObligationInstance::factory()->create(['member_obligation_id' => $obligation->id, 'kind' => InstanceKind::Payment, 'tax_nature' => 'patente', 'due_date' => '2026-03-20', 'amount_due' => 40_000, 'status' => InstanceStatus::ToPay]);
        $training = Training::factory()->create(['organization_id' => $this->cga->id, 'title' => 'Atelier TVA', 'status' => TrainingStatus::Published, 'published_at' => now()]);
        $attendee = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $this->tee->id]);
        DB::table('training_registrations')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'training_id' => $training->id, 'membership_id' => $attendee->id, 'member_id' => $this->tee->id,
            'user_id' => $attendee->user_id, 'registered_by' => $this->manager->user_id, 'status' => 'registered', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $space = exportSpace($this->manager);

        $concerns = exportSheets(exportOf($space, '/api/v1/cga/exports', ['type' => 'concerns', 'period' => 'month']))['Préoccupations'];
        expect(array_column(array_slice($concerns, 1), 3))->toBe(['Question TVA', 'Question du Cabinet'])
            ->and($concerns[1][1])->toBe('ALPHA TEE');
        $obligations = exportSheets(exportOf($space, '/api/v1/cga/exports', ['type' => 'obligations', 'period' => 'month', 'filters' => ['statuses' => ['to_pay']]]))['Échéances'];
        expect($obligations[1])->toMatchArray([0 => '20/03/2026', 1 => 'ALPHA TEE', 7 => 40_000, 9 => 40_000]);
        $registrations = exportSheets(exportOf($space, '/api/v1/cga/exports', ['type' => 'training_registrations']))['Inscrits'];
        expect($registrations[0])->toContain('Entreprise')
            ->and($registrations[1])->toContain('Atelier TVA')->toContain('ALPHA TEE');
    });

    it('ne montre à chacun que ses exports ; un export d\'une autre personne est refusé (403), d\'un autre centre introuvable (404)', function () {
        $mine = exportOf(exportSpace($this->manager), '/api/v1/cga/exports', ['type' => 'members']);
        exportOf(exportSpace($this->expert), '/api/v1/cga/exports', ['type' => 'members']);
        $elsewhere = Export::factory()->create();

        exportSpace($this->manager)->getJson('/api/v1/cga/exports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        exportSpace($this->expert)->getJson("/api/v1/cga/exports/{$mine}")->assertForbidden();
        exportSpace($this->manager)->getJson("/api/v1/cga/exports/{$elsewhere->id}")->assertNotFound();
    });

    it('journalise puis relance une erreur de production ; au dernier échec l\'export passe en échec', function () {
        Queue::fake();
        $id = exportOf(exportSpace($this->manager), '/api/v1/cga/exports', ['type' => 'members']);
        Queue::assertPushed(GenerateExport::class, fn (GenerateExport $job): bool => $job->exportId === $id && $job->queue === 'imports');
        Log::spy();
        app()->bind(MembersExporter::class, static fn () => new class implements Exporter
        {
            public function filterRules(): array
            {
                return [];
            }

            public function sheets(ExportCriteria $criteria): iterable
            {
                throw new RuntimeException('Lecture impossible');
            }
        });
        $job = new GenerateExport($id, $this->manager->id);

        expect(fn () => $job->handle(app(TenantContext::class), app(RunExport::class)))->toThrow(RuntimeException::class);
        $job->failed(new RuntimeException('Échec'));

        Log::shouldHaveReceived('error')->once();
        exportSpace($this->manager)->getJson("/api/v1/cga/exports/{$id}")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.file', null);
        app(RunExport::class)->execute(Tenancy::bypass(static fn () => Export::query()->findOrFail($id), 'Test.'));
        exportSpace($this->manager)->getJson("/api/v1/cga/exports/{$id}")->assertJsonPath('data.status', 'failed');
    });
});

describe('exports du CNCGA (F-EXP-01)', function () {
    it('exporte les cotisations, les préoccupations des CGA, les inscrits du CNCGA sans entreprise, et les statistiques', function () {
        $due = NetworkDue::factory()->create(['organization_id' => $this->cga->id, 'year' => 2026, 'amount_due' => 750_000, 'amount_paid' => 200_000]);
        DB::table('network_due_payments')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'network_due_id' => $due->id, 'amount' => 200_000, 'paid_on' => '2026-03-05', 'method' => 'transfer', 'source' => 'cncga_entry', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        NetworkDue::factory()->create(['organization_id' => $this->cga->id, 'year' => 2025, 'amount_due' => 700_000]);
        Concern::factory()->toNetwork()->create(['organization_id' => $this->cga->id, 'subject' => 'Agrément', 'created_at' => '2026-03-02 10:00:00']);
        $member = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'SECRET SARL']);
        $training = Training::factory()->create(['organization_id' => $this->cncga->id, 'title' => 'Forum national', 'status' => TrainingStatus::Published, 'published_at' => now()]);
        $attendee = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $member->id]);
        DB::table('training_registrations')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'training_id' => $training->id, 'membership_id' => $attendee->id, 'member_id' => $member->id,
            'user_id' => $attendee->user_id, 'registered_by' => $attendee->user_id, 'status' => 'registered', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stat_org_daily')->insert([
            'organization_id' => $this->cga->id, 'day' => '2026-03-20', 'active_members' => 3, 'members_rme' => 1, 'members_tee' => 2, 'members_rsi' => 0, 'new_members' => 0,
            'radiations' => 0, 'fees_due' => 0, 'fees_paid' => 0, 'tax_paid_total' => 90_000, 'tax_paid_by_nature' => '{"patente": 90000}', 'declared_revenue' => 5_000_000,
            'members_by_sector' => '{}', 'members_by_commune' => '{}', 'concerns_opened' => 0, 'concerns_closed' => 0, 'concerns_responded' => 0, 'computed_at' => now(),
        ]);
        $space = exportSpace($this->president);

        $dues = exportSheets(exportOf($space, '/api/v1/network/exports', ['type' => 'dues']))['Cotisations'];
        expect(array_slice($dues, 1))->toHaveCount(1)
            ->and($dues[1])->toMatchArray([0 => 2026, 1 => $this->cga->name, 3 => 750_000, 4 => 200_000, 5 => 550_000, 8 => '05/03/2026']);
        expect(array_slice(exportSheets(exportOf($space, '/api/v1/network/exports', ['type' => 'dues', 'period' => 'custom', 'from' => '2025-01-01', 'to' => '2026-03-31']))['Cotisations'], 1))->toHaveCount(2);
        expect(exportSheets(exportOf($space, '/api/v1/network/exports', ['type' => 'network_concerns']))['Préoccupations des CGA'][1])->toMatchArray([1 => $this->cga->name, 3 => 'Agrément']);
        $registrations = exportSheets(exportOf($space, '/api/v1/network/exports', ['type' => 'training_registrations']))['Inscrits'];
        expect($registrations[0])->not->toContain('Entreprise')
            ->and(json_encode($registrations))->not->toContain('SECRET SARL');
        $statistics = exportSheets(exportOf($space, '/api/v1/network/exports', ['type' => 'statistics']));
        expect(array_keys($statistics))->toBe(['Synthèse', 'Recettes par nature', 'Par CGA', 'Par régime', 'Par secteur', 'Par DR', 'Informations'])
            ->and($statistics['Recettes par nature'][1])->toBe(['patente', 90_000])
            ->and($statistics['Synthèse'][4][1])->toBe('Non disponible — saisir les références 2026')
            ->and($statistics['Par CGA'][1])->toBe([$this->cga->name, 3, 90_000, 5_000_000]);

        $space->postJson('/api/v1/network/exports', ['type' => 'members'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $space->postJson('/api/v1/network/exports', ['type' => 'statistics', 'filters' => ['portfolio' => 'cga']])->assertUnprocessable()->assertJsonValidationErrors('filters');
        $training = Membership::factory()->role(Role::CncgaTraining)->create(['organization_id' => $this->cncga->id]);
        exportSpace($training)->postJson('/api/v1/network/exports', ['type' => 'dues'])->assertForbidden();
    });
});

describe('plaquette statistique annuelle (F-STA-03)', function () {
    beforeEach(function () {
        fakePdfConverter();
        $this->seed(TemplateSeeder::class);
        DB::table('stat_org_daily')->insert([
            'organization_id' => $this->cga->id, 'day' => '2026-03-20', 'active_members' => 3, 'members_rme' => 1, 'members_tee' => 2, 'members_rsi' => 0, 'new_members' => 0,
            'radiations' => 0, 'fees_due' => 0, 'fees_paid' => 0, 'tax_paid_total' => 90_000, 'tax_paid_by_nature' => '{"patente": 90000}', 'declared_revenue' => 5_000_000,
            'members_by_sector' => '{"none": 3}', 'members_by_commune' => '{}', 'concerns_opened' => 0, 'concerns_closed' => 0, 'concerns_responded' => 0, 'computed_at' => now(),
        ]);
    });

    it('produit la plaquette PDF de l\'année par la chaîne documentaire, avec les statistiques nationales, invisible des adhérents', function () {
        $missing = $this->cncga->fresh()->missingProfileFields();
        expect($missing)->toBe([]);

        $id = exportSpace($this->president)->postJson('/api/v1/network/statistics/brochures', ['year' => 2026])->assertStatus(202)->assertJsonPath('data.year', 2026)->json('data.id');

        exportSpace($this->president)->getJson('/api/v1/network/statistics/brochures')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status', 'valid')
            ->assertJsonPath('data.0.title', 'Plaquette statistique 2026')
            ->assertJsonPath('data.0.pdf.url', fn (string $url): bool => str_contains($url, 'signature='));
        $document = Tenancy::bypass(static fn (): GeneratedDocument => GeneratedDocument::query()->with('docx')->findOrFail($id), 'Test.');
        $zip = new ZipArchive;
        $zip->open(Storage::disk($document->docx->disk)->path($document->docx->path));
        $text = strip_tags((string) $zip->getFromName('word/document.xml'));
        $zip->close();
        expect($document->visible_to_member)->toBeFalse()
            ->and($text)->toContain('Plaquette statistique 2026')->toContain($this->cga->name)->toContain('PATENTE')->toContain('90 000')
            ->and($text)->toContain('Non disponible — saisir les références 2026')
            ->and($text)->not->toContain('${');
    });

    it('refuse une année future ou trop ancienne (422), sans exports.run ou hors du CNCGA (403)', function () {
        exportSpace($this->president)->postJson('/api/v1/network/statistics/brochures', ['year' => 2027])->assertUnprocessable()->assertJsonValidationErrors('year');
        exportSpace($this->president)->postJson('/api/v1/network/statistics/brochures', ['year' => 1999])->assertUnprocessable()->assertJsonValidationErrors('year');
        $board = Membership::factory()->role(Role::CncgaViewer)->create(['organization_id' => $this->cncga->id]);
        exportSpace($board)->postJson('/api/v1/network/statistics/brochures', ['year' => 2026])->assertForbidden();
        exportSpace($board)->getJson('/api/v1/network/statistics/brochures')->assertForbidden();
        exportSpace($this->manager)->postJson('/api/v1/network/statistics/brochures', ['year' => 2026])->assertForbidden();
    });
});
