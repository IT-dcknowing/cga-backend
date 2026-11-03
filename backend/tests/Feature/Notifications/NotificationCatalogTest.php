<?php

use App\Domain\Closing\Events\ClosingPiecesReminderDue;
use App\Domain\Closing\Events\ClosingStepsLate;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Events\RadiationRequested;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Domain\Notifications\Actions\SendFollowUpReminders;
use App\Domain\Notifications\Listeners\NotifyDomainEvents;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\MemberObligation;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Events\InvoiceCertified;
use App\Domain\Payments\Events\InvoiceRejected;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Payments\Events\PaymentDeclared;
use App\Domain\Payments\Events\PaymentRejected;
use App\Domain\Payments\Models\Invoice;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Reports\Events\ReportLate;
use App\Domain\Reports\Events\ReportLegallyLate;
use App\Domain\Reports\Events\ReportReminderDue;
use App\Domain\Reports\Events\ReportTransmitted;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Notifications de la table PRD M13 (06-IMPLEMENTATION-PLAN étape 6.3) : une ligne, un test — destinataires,
 * espace, texte, écran ; tâches reminders:obligations, fees, documents, agreements, rejouables sans
 * doublon. Les lignes des préoccupations, formations et annonces (Lot 7), des cotisations (6.4) et de
 * Compta Flow (Lot 11) sont testées avec leur module.
 */

beforeEach(function () {
    config(['queue.default' => 'sync']);
    $this->travelTo('2026-11-03 10:00:00');
    $this->manager = Membership::factory()->role(Role::CgaManager)->create();
    $this->cga = $this->manager->organization;
    $this->expert = Membership::factory()->role(Role::CgaAccountantExpert)->create(['organization_id' => $this->cga->id]);
    $this->secretary = Membership::factory()->role(Role::CgaSecretary)->create(['organization_id' => $this->cga->id]);
    $this->member = Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'BOUTIQUE AWA']);
    $this->access = Membership::factory()->role(Role::MemberOwner)->create(['organization_id' => $this->cga->id, 'member_id' => $this->member->id]);
    $this->president = Membership::factory()->role(Role::CncgaPresident)->create();
});

/**
 * Types des notifications reçues dans l'espace d'un rattachement.
 *
 * @return list<string>
 */
function received(Membership $membership): array
{
    return $membership->user->notifications()->where('data->membership_id', $membership->id)->orderBy('created_at')->pluck('type')->all();
}

/**
 * Données de la notification reçue d'un type (la dernière de ce type).
 *
 * @return array<string, mixed>
 */
function receivedOf(Membership $membership, string $type): array
{
    return (array) $membership->user->notifications()->where('data->membership_id', $membership->id)->where('type', $type)->latest()->first()?->data;
}

/**
 * Textes des notifications reçues dans l'espace d'un rattachement.
 *
 * @return list<string>
 */
function bodies(Membership $membership): array
{
    return $membership->user->notifications()->where('data->membership_id', $membership->id)->get()->map(static fn ($notification): string => (string) $notification->data['body'])->all();
}

function dueInstance(Member $member, string $due, array $attributes = []): ObligationInstance
{
    return ObligationInstance::factory()->create([
        'member_obligation_id' => MemberObligation::factory()->create(['member_id' => $member->id])->id,
        'kind' => InstanceKind::Payment, 'tax_nature' => 'tee', 'label' => 'TEE', 'period_label' => 'T4 2026',
        'period_start' => '2026-10-01', 'period_end' => '2026-12-31', 'due_date' => $due, 'status' => InstanceStatus::ToPay, ...$attributes,
    ]);
}

it('nouveau document disponible : à l\'adhérent, seulement s\'il lui est visible', function () {
    $visible = GeneratedDocument::factory()->create(['member_id' => $this->member->id, 'title' => 'Attestation d\'adhésion 2026', 'visible_to_member' => true]);
    $hidden = GeneratedDocument::factory()->create(['member_id' => $this->member->id, 'visible_to_member' => false]);

    DocumentReady::dispatch($visible);
    DocumentReady::dispatch($hidden);

    expect(received($this->access))->toBe(['document_available'])
        ->and(receivedOf($this->access, 'document_available'))->toMatchArray(['body' => 'Le document « Attestation d\'adhésion 2026 » est disponible dans votre espace.', 'link' => '/adherent/documents'])
        ->and(received($this->manager))->toBe([]);
});

it('échéance à J-7 puis J-1 : à l\'adhérent, une fois ; échéance dépassée : à l\'adhérent et aux agents des obligations', function () {
    dueInstance($this->member, '2026-11-10');
    dueInstance($this->member, '2026-11-04', ['period_label' => 'T3 2026']);
    dueInstance($this->member, '2026-11-10', ['period_label' => 'payée', 'status' => InstanceStatus::Paid]);
    $overdue = dueInstance($this->member, '2026-10-20', ['period_label' => 'T2 2026']);

    $this->artisan('reminders:obligations')->assertSuccessful()->expectsOutputToContain('upcoming : 2, overdue : 1');
    $this->artisan('reminders:obligations')->assertSuccessful()->expectsOutputToContain('upcoming : 0, overdue : 0');

    expect(received($this->access))->toEqualCanonicalizing(['obligation_due', 'obligation_due', 'obligation_overdue'])
        ->and(received($this->manager))->toBe(['obligation_overdue'])
        ->and(received($this->expert))->toBe(['obligation_overdue'])
        ->and(received($this->secretary))->toBe([])
        ->and(Tenancy::bypass(static fn () => $overdue->fresh()->overdue_notified_at, 'Test.'))->not->toBeNull();
});

it('honoraires impayés : à l\'adhérent le jour du passage en impayé, puis tous les 30 jours', function () {
    MemberCharge::factory()->create(['member_id' => $this->member->id, 'status' => ChargeStatus::Overdue, 'overdue_since' => '2026-11-03', 'amount' => 15_000, 'amount_paid' => 5_000]);

    $this->artisan('reminders:fees')->assertSuccessful()->expectsOutputToContain('fees : 1');
    $this->artisan('reminders:fees')->assertSuccessful()->expectsOutputToContain('fees : 0');
    $this->travelTo('2026-11-04 10:00:00');
    $this->artisan('reminders:fees')->assertSuccessful()->expectsOutputToContain('fees : 0');
    $this->travelTo('2026-12-03 10:00:00');
    $this->artisan('reminders:fees')->assertSuccessful()->expectsOutputToContain('fees : 1');

    expect(received($this->access))->toBe(['fees_unpaid', 'fees_unpaid'])
        ->and(receivedOf($this->access, 'fees_unpaid')['body'])->toBe('1 échéance(s) d\'honoraires restent impayées, pour 10 000 FCFA au total.');
});

it('pièces en attente depuis 30 jours : aux agents et à l\'adhérent, puis tous les 30 jours', function () {
    DB::table('member_pieces')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $this->cga->id, 'member_id' => $this->member->id, 'piece_type' => 'id_document',
        'status' => 'expected', 'created_at' => '2026-10-04 08:00:00', 'updated_at' => '2026-10-04 08:00:00',
    ]);

    $this->artisan('reminders:documents')->assertSuccessful()->expectsOutputToContain('documents : 1');
    $this->artisan('reminders:documents')->assertSuccessful()->expectsOutputToContain('documents : 0');

    expect(received($this->access))->toBe(['pieces_pending'])
        ->and(received($this->manager))->toBe(['pieces_pending'])
        ->and(receivedOf($this->manager, 'pieces_pending')['link'])->toBe("/cga/adherents/{$this->member->id}");
});

it('paiement déclaré : aux agents des paiements ; confirmé ou rejeté : à l\'adhérent', function () {
    $payment = MemberPayment::factory()->create(['member_id' => $this->member->id, 'amount' => 25_000]);

    PaymentDeclared::dispatch($payment);
    PaymentConfirmed::dispatch($payment);
    PaymentRejected::dispatch($payment);

    expect(received($this->manager))->toBe(['payment_declared'])
        ->and(receivedOf($this->manager, 'payment_declared')['title'])->toBe('Paiement déclaré par BOUTIQUE AWA')
        ->and(received($this->secretary))->toBe([])
        ->and(received($this->access))->toBe(['payment_reviewed', 'payment_reviewed'])
        ->and(bodies($this->access))->toContain('Votre paiement de 25 000 FCFA a été confirmé par votre CGA.', 'Votre paiement de 25 000 FCFA a été rejeté par votre CGA.');
});

it('facture certifiée FNE : au client ; certification rejetée : à qui émet les factures', function () {
    $invoice = new Invoice;
    $invoice->forceFill(['organization_id' => $this->cga->id, 'member_id' => $this->member->id, 'number' => 'FAC-2026-0042']);

    InvoiceCertified::dispatch($invoice);
    InvoiceRejected::dispatch($invoice);

    expect(received($this->access))->toBe(['invoice_certified'])
        ->and(received($this->manager))->toBe(['invoice_rejected'])
        ->and(receivedOf($this->manager, 'invoice_rejected')['title'])->toBe('Facture FAC-2026-0042 rejetée par la DGI');
});

it('radiation demandée puis validée (effet 6) : à l\'adhérent et au gérant', function () {
    $radiation = Tenancy::bypass(fn () => (new Radiation)->forceFill(['organization_id' => $this->cga->id, 'member_id' => $this->member->id, 'effective_date' => '2026-12-31']), 'Test.');

    RadiationRequested::dispatch($radiation);
    // Les autres abonnés de la validation (courrier au CDI, attestations) agissent dans l'espace du centre :
    // seul l'abonné des notifications est appelé ici.
    app(NotifyDomainEvents::class)->radiationValidated(new RadiationValidated($radiation));
    expect(NotifyDomainEvents::LISTENS)->toHaveKey(RadiationValidated::class)
        ->and(app('events')->getListeners(RadiationValidated::class))->not->toBeEmpty();

    expect(received($this->access))->toBe(['radiation_requested', 'radiation_validated'])
        ->and(received($this->manager))->toBe(['radiation_requested', 'radiation_validated'])
        ->and(received($this->expert))->toBe([])
        ->and(receivedOf($this->access, 'radiation_validated')['body'])->toBe('La radiation du dossier BOUTIQUE AWA est validée, avec effet au 31 décembre 2026.');
});

it('rapport à J-15, J-5, J+5 : au gérant et à l\'expert-comptable ; en retard : au gérant ; hors délai : au gérant et au CNCGA ; transmis : au CNCGA', function () {
    ReportReminderDue::dispatch($this->cga->id, 2026, 3, -15);
    ReportLate::dispatch($this->cga->id, 2026, 3);
    ReportLegallyLate::dispatch($this->cga->id, 2026, 3);
    ReportTransmitted::dispatch($this->cga->id, 2026, 3);

    expect(received($this->manager))->toBe(['report_reminder', 'report_late', 'report_legally_late'])
        ->and(received($this->expert))->toBe(['report_reminder'])
        ->and(received($this->secretary))->toBe([])
        ->and(received($this->president))->toBe(['report_legally_late', 'report_transmitted'])
        ->and(receivedOf($this->president, 'report_transmitted')['body'])->toBe("{$this->cga->name} a enregistré la transmission de son rapport T3 2026.");
});

it('campagne de clôture : rappel de pièces à l\'adhérent, retards aux agents de la clôture', function () {
    $campaign = ClosingCampaign::factory()->create(['organization_id' => $this->cga->id]);
    $item = ClosingCampaignItem::factory()->create(['campaign_id' => $campaign->id, 'member_id' => $this->member->id]);

    ClosingPiecesReminderDue::dispatch(Tenancy::bypass(static fn () => $item->load('campaign'), 'Test.'), 'before');
    ClosingStepsLate::dispatch($campaign, ['pieces' => 2, 'regularisation' => 1]);

    expect(received($this->access))->toBe(['closing_pieces_reminder'])
        ->and(receivedOf($this->access, 'closing_pieces_reminder')['body'])->toBe('Merci de remettre à votre CGA les pièces comptables de l\'exercice 2026 avant le 30 janvier 2027.')
        ->and(received($this->manager))->toBe(['closing_steps_late'])
        ->and(receivedOf($this->manager, 'closing_steps_late')['title'])->toBe('Clôture 2026 : dossiers en retard')
        ->and(received($this->secretary))->toBe([]);
});

it('agrément à 6 puis 3 mois, RSI au portefeuille CGA à J+60, impayés de plus de 3 mois : alertes seulement', function () {
    Tenancy::bypass(fn () => $this->cga->forceFill(['agreement_expires_at' => '2027-05-03'])->save(), 'Test.');
    Member::factory()->active()->create(['organization_id' => $this->cga->id, 'company_name' => 'SARL AU REEL', 'regime' => TaxRegime::Rsi, 'rsi_since' => '2026-09-04']);
    MemberCharge::factory()->create(['member_id' => $this->member->id, 'status' => ChargeStatus::Overdue, 'overdue_since' => '2026-08-03']);

    $this->artisan('reminders:agreements')->assertSuccessful()->expectsOutputToContain('agreements : 1, rsi : 1, fees : 1');
    $this->artisan('reminders:agreements')->assertSuccessful()->expectsOutputToContain('agreements : 0, rsi : 0, fees : 0');

    expect(received($this->manager))->toEqualCanonicalizing(['agreement_expiring', 'rsi_not_radiated', 'fees_unpaid_alert'])
        ->and(received($this->president))->toBe(['agreement_expiring'])
        ->and(received($this->expert))->toBe(['rsi_not_radiated'])
        ->and(Tenancy::bypass(fn () => Member::query()->whereKey($this->member->id)->value('status')->value, 'Test.'))->toBe('active');
});

it('tâches planifiées chaque jour ; une erreur est journalisée puis relancée', function () {
    $events = collect(app(Schedule::class)->events());
    foreach (['obligations' => '0 7 * * *', 'fees' => '5 7 * * *', 'documents' => '10 7 * * *', 'agreements' => '25 7 * * *'] as $task => $cron) {
        expect($events->first(fn ($event): bool => str_ends_with((string) $event->command, "reminders:{$task}"))->expression)->toBe($cron);
    }

    Log::spy();
    $this->mock(SendFollowUpReminders::class)->shouldReceive('fees')->andThrow(new RuntimeException('panne'));
    expect(fn () => $this->artisan('reminders:fees')->run())->toThrow(RuntimeException::class, 'panne');
    Log::shouldHaveReceived('error')->once();
    expect(Organization::query()->count())->toBeGreaterThan(1)->and(DocumentType::Uploaded->value)->toBe('uploaded');
});
