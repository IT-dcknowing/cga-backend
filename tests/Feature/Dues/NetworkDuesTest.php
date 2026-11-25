<?php

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Dues\Actions\DuesReminders;
use App\Domain\Dues\Actions\OpenDuesYear;
use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Dues\Models\NetworkDuePayment;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\TemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Cotisation CNCGA (06-IMPLEMENTATION-PLAN étape 6.4, PRD M14, RG-COT-01 à 06) : appel annuel pour
 * chaque CGA du registre, prorata semestriel, déclaration du CGA avec preuve, saisie, confirmation avec
 * reçu ou rejet par le CNCGA, statuts, relances J+30 / 60 / 90, alerte à la Présidente sans suspension.
 */

beforeEach(function () {
    Storage::fake();
    $this->seed(TemplateSeeder::class);
    config(['queue.default' => 'sync']);
    fakePdfConverter();
    $this->travelTo('2026-03-10 10:00:00');
    Organization::factory()->cncga()->profileComplete()->create(['numbering_abbreviation' => 'CNCGA']);
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create();
    $this->duesOfficer = Membership::factory()->role(Role::CncgaDues)->create();
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $this->cga->id]);
});

function dueOf(Organization $cga, int $year = 2026): NetworkDue
{
    return Tenancy::bypass(static fn () => NetworkDue::query()->where('organization_id', $cga->id)->where('year', $year)->firstOrFail(), 'Test.');
}

function declareDues(Membership $agent, NetworkDue $due, array $overrides = [])
{
    return inSpace($agent)->post("/api/v1/cga/dues/{$due->id}/payments", [
        'amount' => 300_000, 'paid_on' => '2026-03-09', 'method' => 'transfer', 'reference' => 'VIR-0042',
        'proof' => UploadedFile::fake()->createWithContent('virement.pdf', "%PDF-1.4\n%%EOF"), ...$overrides,
    ]);
}

function typesFor(Membership $membership): array
{
    return $membership->user->notifications()->where('data->membership_id', $membership->id)->pluck('type')->all();
}

describe('appel annuel (F-COT-01)', function () {
    it('RG-COT-01 · crée la cotisation de l\'année pour chaque CGA du registre, abonné ou non, une seule fois', function () {
        $unsubscribed = Organization::factory()->create(['subscription_status' => 'none']);
        $terminated = Organization::factory()->create(['status' => OrganizationStatus::Terminated]);

        $this->artisan('dues:open-year')->assertSuccessful()->expectsOutputToContain('2 cotisation(s) créée(s)');
        $this->artisan('dues:open-year')->assertSuccessful()->expectsOutputToContain('0 cotisation(s) créée(s)');
        $this->artisan('dues:open-year --year=26')->assertExitCode(2);

        expect(dueOf($this->cga))->amount_due->toBe(750_000)->deadline->toDateString()->toBe('2026-06-30')->status->toBe(DuesStatus::Due)
            ->and(dueOf($unsubscribed)->amount_due)->toBe(750_000)
            ->and(Tenancy::bypass(fn () => NetworkDue::query()->where('organization_id', $terminated->id)->exists(), 'Test.'))->toBeFalse();
    });

    it('RG-COT-01 · montant et date butoir paramétrables ; RG-COT-02 · prorata semestriel d\'un CGA agréé au 2e semestre', function () {
        app(SettingsService::class)->set('dues.amount', 800_000);
        app(SettingsService::class)->set('dues.deadline', '09-30');
        $lateComer = Organization::factory()->create(['agreement_date' => '2027-08-15']);
        $earlyComer = Organization::factory()->create(['agreement_date' => '2027-02-15']);

        $this->artisan('dues:open-year --year=2027')->assertSuccessful();

        expect(dueOf($lateComer, 2027))->prorata_ratio->toBe('0.500')->amount_due->toBe(400_000)->deadline->toDateString()->toBe('2027-09-30')
            ->and(dueOf($earlyComer, 2027)->amount_due)->toBe(800_000)
            ->and(dueOf($this->cga, 2027)->base_amount)->toBe(800_000);
    });

    it('chaque 1er janvier à 00:30, relances chaque jour à 07:20', function () {
        $events = collect(app(Schedule::class)->events());

        expect($events->first(fn ($event): bool => str_contains((string) $event->command, 'dues:open-year'))->expression)->toBe('30 0 1 1 *')
            ->and($events->first(fn ($event): bool => str_contains((string) $event->command, 'reminders:dues'))->expression)->toBe('20 7 * * *');
    });
});

describe('paiements (F-COT-03, 04, RG-COT-03, 04)', function () {
    beforeEach(function () {
        $this->artisan('dues:open-year')->assertSuccessful();
        $this->due = dueOf($this->cga);
    });

    it('F-COT-03 · le gérant déclare un paiement avec sa preuve : à confirmer, le CNCGA est averti', function () {
        declareDues($this->manager, $this->due)
            ->assertCreated()
            ->assertJsonPath('data.pending_confirmation', true)
            ->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.payments.0.status', 'pending_confirmation');

        expect(typesFor($this->duesOfficer))->toBe(['dues_payment_declared']);
        declareDues($this->manager, $this->due, ['proof' => null])->assertUnprocessable()->assertJsonValidationErrors('proof');
        declareDues($this->manager, $this->due, ['amount' => 900_000])->assertStatus(409)->assertJsonPath('code', 'dues_payment_exceeds_balance');
        declareDues($this->expert, $this->due)->assertForbidden();
        inSpace($this->expert)->getJson('/api/v1/cga/dues')->assertOk()->assertJsonPath('data.0.year', 2026)->assertJsonPath('meta.can.declare', false);
    });

    it('RG-COT-04 · confirmé : reçu numéroté, réglé et statut partiel puis à jour ; rejeté : motif, gérant averti', function () {
        $first = declareDues($this->manager, $this->due)->json('data.payments.0.id');

        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/payments/{$first}/confirm")
            ->assertOk()->assertJsonPath('data.amount_paid', 300_000)->assertJsonPath('data.status', 'partial')->assertJsonPath('data.balance', 450_000)
            ->assertJsonPath('data.payments.0.receipt_number', 'CNC-2026-00001');
        $payment = Tenancy::bypass(static fn () => NetworkDuePayment::query()->with('receipt')->findOrFail($first), 'Test.');
        expect($payment->receipt)->status->toBe(DocumentStatus::Valid)->type->value->toBe('dues_receipt')
            ->and(typesFor($this->manager))->toBe(['dues_payment_reviewed']);
        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/payments/{$first}/confirm")->assertStatus(409)->assertJsonPath('code', 'dues_payment_not_pending');

        $second = declareDues($this->manager, $this->due, ['amount' => 450_000])->assertJsonPath('data.payments.1.receipt_url', fn (?string $url): bool => str_contains((string) $url, 'signature='))
            ->json('data.payments.0.id');
        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/payments/{$second}/reject", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/payments/{$second}/reject", ['reason' => 'Virement introuvable'])
            ->assertOk()->assertJsonPath('data.amount_paid', 300_000);

        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/{$this->due->id}/payments", ['amount' => 450_000, 'paid_on' => '2026-03-10', 'method' => 'cheque', 'reference' => 'CHQ-77'])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0);
        declareDues($this->manager, $this->due, ['amount' => 1])->assertStatus(409)->assertJsonPath('code', 'dues_already_paid');
    });

    it('RG-COT-03 · la cotisation est distincte de l\'abonnement et des paiements des adhérents', function () {
        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/{$this->due->id}/payments", ['amount' => 750_000, 'paid_on' => '2026-03-10', 'method' => 'transfer'])->assertCreated();

        expect(Tenancy::bypass(static fn () => MemberPayment::query()->count(), 'Test.'))->toBe(0)
            ->and(Organization::query()->findOrFail($this->cga->id)->subscription_status->value)->toBe('active')
            ->and(Tenancy::bypass(static fn () => GeneratedDocument::query()->where('type', 'receipt')->count(), 'Test.'))->toBe(0);
    });

    it('tableau du CNCGA : toutes les cotisations de l\'année, totaux ; le CGA ne voit que la sienne', function () {
        $other = Membership::factory()->role(Role::CgaManager)->create();
        $this->artisan('dues:open-year')->assertSuccessful();

        inSpace($this->president)->getJson('/api/v1/network/dues?year=2026')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.totals.amount_due', 1_500_000)->assertJsonPath('meta.counts.due', 2);
        inSpace($this->president)->getJson("/api/v1/network/dues/{$this->due->id}")->assertOk()->assertJsonPath('data.cga.id', $this->cga->id);
        inSpace($this->president)->getJson('/api/v1/network/dues/inconnue')->assertNotFound();
        inSpace($this->president)->getJson('/api/v1/network/dues')->assertUnprocessable()->assertJsonValidationErrors('year');
        inSpace($other)->getJson('/api/v1/cga/dues')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount_due', 750_000);
        declareDues($other, $this->due)->assertNotFound();
        inSpace($this->manager)->getJson('/api/v1/network/dues?year=2026')->assertForbidden();
        inSpace($this->manager)->postJson("/api/v1/network/dues/{$this->due->id}/remind")->assertForbidden();
    });
});

describe('relances (F-COT-05, RG-COT-05, 06)', function () {
    it('RG-COT-04 · RG-COT-05 · impayée après la date butoir ; relances au gérant à J+30, J+60, J+90 ; relance manuelle', function () {
        $this->artisan('dues:open-year')->assertSuccessful();
        $due = dueOf($this->cga);

        $this->travelTo('2026-07-30 07:20:00');
        $this->artisan('reminders:dues')->assertSuccessful()->expectsOutputToContain('1 relance(s), 0 alerte(s)');
        $this->artisan('reminders:dues')->assertSuccessful()->expectsOutputToContain('0 relance(s)');
        expect(dueOf($this->cga))->status->toBe(DuesStatus::Unpaid)->reminder_count->toBe(1);

        $this->travelTo('2026-08-29 07:20:00');
        $this->artisan('reminders:dues')->assertSuccessful()->expectsOutputToContain('1 relance(s)');
        $this->travelTo('2026-08-30 10:00:00');
        $this->flushSession();
        auth()->forgetGuards();
        inSpace($this->duesOfficer)->postJson("/api/v1/network/dues/{$due->id}/remind")->assertOk()->assertJsonPath('data.reminder_count', 3);
        expect(typesFor($this->manager))->toBe(['dues_reminder', 'dues_reminder', 'dues_reminder']);
    });

    it('RG-COT-06 · à J+90, la Présidente est alertée qu\'elle peut suspendre ; aucune suspension automatique', function () {
        $this->artisan('dues:open-year')->assertSuccessful();
        $this->travelTo('2026-09-28 07:20:00');

        $this->artisan('reminders:dues')->assertSuccessful()->expectsOutputToContain('1 relance(s), 1 alerte(s)');

        expect(typesFor($this->president))->toBe(['dues_alert'])
            ->and(typesFor($this->duesOfficer))->toBe([])
            ->and(Organization::query()->findOrFail($this->cga->id)->status)->toBe(OrganizationStatus::Active);
    });
});

describe('facture de cotisation certifiée FNE (étape 6.9)', function () {
    it('RG-FNE-01 · le CNCGA facture la cotisation : une seule facture, remise au CGA une fois certifiée ; aucune suspension', function () {
        $this->artisan('dues:open-year')->assertSuccessful();
        $due = dueOf($this->cga);

        $invoice = inSpace($this->president)->postJson("/api/v1/network/dues/{$due->id}/invoice")
            ->assertOk()->assertJsonPath('data.invoice.status', 'pending_certification')->assertJsonPath('data.invoice.pdf_url', null)
            ->json('data.invoice');
        inSpace($this->president)->postJson("/api/v1/network/dues/{$due->id}/invoice")->assertStatus(409)->assertJsonPath('code', 'dues_already_invoiced');
        inSpace($this->manager)->getJson('/api/v1/cga/dues')->assertOk()->assertJsonPath('data.0.invoice', null);

        inSpace($this->president)->post("/api/v1/cga/invoices/{$invoice['id']}/manual-certification", [
            'fne_number' => 'FNE-CNCGA-0001', 'file' => UploadedFile::fake()->createWithContent('fne.pdf', "%PDF-1.4\n%%EOF"),
        ])->assertOk();

        $certified = inSpace($this->manager)->getJson('/api/v1/cga/dues')->assertOk()->assertJsonPath('data.0.invoice.status', 'certified');
        expect($certified->json('data.0.invoice.pdf_url'))->toContain('signature=')
            ->and(typesFor($this->manager))->toContain('invoice_certified')
            ->and(Organization::query()->findOrFail($this->cga->id)->status)->toBe(OrganizationStatus::Active);
        inSpace($this->manager)->postJson("/api/v1/network/dues/{$due->id}/invoice")->assertForbidden();
    });
});

it('onglet Cotisations de la fiche CGA : toutes les années d\'un centre, avec sa facture', function () {
    $this->artisan('dues:open-year')->assertSuccessful();
    $this->artisan('dues:open-year --year=2025')->assertSuccessful();

    inSpace($this->president)->getJson("/api/v1/network/dues?cga={$this->cga->id}")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.year', 2026)->assertJsonPath('data.1.year', 2025)->assertJsonPath('data.0.invoice', null);
    inSpace($this->president)->getJson('/api/v1/network/dues?cga=court')->assertUnprocessable()->assertJsonValidationErrors('cga');
});

it('une panne de l\'appel annuel ou des relances est journalisée puis relancée (la tâche échoue)', function () {
    $this->mock(OpenDuesYear::class, fn ($mock) => $mock->shouldReceive('execute')->andThrow(new RuntimeException('base indisponible')));
    $this->mock(DuesReminders::class, fn ($mock) => $mock->shouldReceive('daily')->andThrow(new RuntimeException('base indisponible')));
    Log::spy();

    expect(fn () => $this->artisan('dues:open-year')->run())->toThrow(RuntimeException::class, 'base indisponible')
        ->and(fn () => $this->artisan('reminders:dues')->run())->toThrow(RuntimeException::class, 'base indisponible');
    Log::shouldHaveReceived('error')->twice();
});
