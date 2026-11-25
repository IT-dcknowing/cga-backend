<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Events\ClosingPiecesReminderDue;
use App\Domain\Closing\Events\ClosingStepsLate;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Events\RadiationRequested;
use App\Domain\Members\Events\RadiationValidated;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Support\Recipients;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Payments\Events\InvoiceCertified;
use App\Domain\Payments\Events\InvoiceRejected;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Domain\Payments\Events\PaymentDeclared;
use App\Domain\Payments\Events\PaymentRejected;
use App\Domain\Reports\Events\ReportLate;
use App\Domain\Reports\Events\ReportLegallyLate;
use App\Domain\Reports\Events\ReportReminderDue;
use App\Domain\Reports\Events\ReportTransmitted;
use App\Domain\Users\Enums\Role;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;

/**
 * Notifications des événements de la plateforme (PRD M13, 6.3) : chaque événement d'un module devient
 * une notification à ses destinataires — adhérent (accès en ligne du dossier), agents du centre selon
 * leurs droits, gérant, CNCGA. Les canaux, la plage d'envoi et le consentement relèvent du moteur
 * (NotificationRouting) ; les notifications partent en file après la validation de la transaction.
 */
class NotifyDomainEvents
{
    public function __construct(private readonly Recipients $recipients) {}

    /**
     * Événements écoutés et méthode de chacun. Enregistrés par AppServiceProvider sans instancier
     * l'écouteur au démarrage : ses dépendances (droits, cache) ne sont résolues qu'au premier événement.
     *
     * @var array<class-string, string>
     */
    public const LISTENS = [
        DocumentReady::class => 'documentReady',
        PaymentDeclared::class => 'paymentDeclared',
        PaymentConfirmed::class => 'paymentConfirmed',
        PaymentRejected::class => 'paymentRejected',
        InvoiceCertified::class => 'invoiceCertified',
        InvoiceRejected::class => 'invoiceRejected',
        RadiationRequested::class => 'radiationRequested',
        RadiationValidated::class => 'radiationValidated',
        ReportReminderDue::class => 'reportReminder',
        ReportLate::class => 'reportLate',
        ReportLegallyLate::class => 'reportLegallyLate',
        ReportTransmitted::class => 'reportTransmitted',
        ClosingPiecesReminderDue::class => 'closingPieces',
        ClosingStepsLate::class => 'closingLate',
    ];

    /** Nouveau document disponible pour l'adhérent (RG-DOC-04 : visible de lui seulement s'il est publié). */
    public function documentReady(DocumentReady $event): void
    {
        $document = $event->document;
        if ($document->member_id === null || ! $document->visible_to_member || $document->status !== DocumentStatus::Valid) {
            return;
        }
        $this->recipients->notify($this->recipients->memberAccesses($document->member_id), 'document_available', ['title' => $document->title], '/adherent/documents');
    }

    /** Paiement déclaré par l'adhérent : aux agents qui confirment les paiements. */
    public function paymentDeclared(PaymentDeclared $event): void
    {
        $payment = $event->payment;
        $this->recipients->notify(
            $this->recipients->agents($payment->organization_id, 'member_payments.manage'),
            'payment_declared',
            ['member' => $this->memberName($payment->member_id), 'amount' => DocumentFormat::amount($payment->amount)],
            "/cga/adherents/{$payment->member_id}",
        );
    }

    public function paymentConfirmed(PaymentConfirmed $event): void
    {
        $this->paymentReviewed($event->payment->member_id, $event->payment->amount, 'confirmed');
    }

    public function paymentRejected(PaymentRejected $event): void
    {
        $this->paymentReviewed($event->payment->member_id, $event->payment->amount, 'rejected');
    }

    /** Facture certifiée FNE disponible : au client (adhérent). */
    public function invoiceCertified(InvoiceCertified $event): void
    {
        $invoice = $event->invoice;
        if ($invoice->member_id !== null) {
            $this->recipients->notify($this->recipients->memberAccesses($invoice->member_id), 'invoice_certified', ['number' => $invoice->number], '/adherent/honoraires');
        }
        // Facture de cotisation du CNCGA (étape 6.9) : au gérant du centre facturé.
        if ($invoice->billed_organization_id !== null) {
            $this->recipients->notify($this->recipients->roles($invoice->billed_organization_id, [Role::CgaManager]), 'invoice_certified', ['number' => $invoice->number], '/cga/cotisation');
        }
    }

    /** Certification rejetée par la DGI : au gérant et à qui émet les factures. */
    public function invoiceRejected(InvoiceRejected $event): void
    {
        $invoice = $event->invoice;
        $this->recipients->notify($this->recipients->agents($invoice->organization_id, 'invoices.issue'), 'invoice_rejected', ['number' => $invoice->number], '/cga/factures');
    }

    /** Radiation demandée : à l'adhérent et au gérant. */
    public function radiationRequested(RadiationRequested $event): void
    {
        $this->radiation($event->radiation->member_id, $event->radiation->organization_id, 'radiation_requested', CarbonImmutable::parse($event->radiation->effective_date));
    }

    /** Radiation validée (effet 6 de F-ADH-06) : à l'adhérent et au gérant. */
    public function radiationValidated(RadiationValidated $event): void
    {
        $this->radiation($event->radiation->member_id, $event->radiation->organization_id, 'radiation_validated', CarbonImmutable::parse($event->radiation->effective_date));
    }

    /** Rapport non transmis à J-15, J-5, J+5 : au gérant et à l'expert-comptable (reports.transmit). */
    public function reportReminder(ReportReminderDue $event): void
    {
        $this->recipients->notify($this->recipients->agents($event->organizationId, 'reports.transmit'), 'report_reminder', $this->report($event->year, $event->quarter), '/cga/rapports');
    }

    /** Rapport en retard au lendemain de J : alerte au gérant. */
    public function reportLate(ReportLate $event): void
    {
        $this->recipients->notify($this->recipients->roles($event->organizationId, [Role::CgaManager]), 'report_late', $this->report($event->year, $event->quarter), '/cga/rapports');
    }

    /** Hors délai réglementaire (J+15) : au gérant et au CNCGA. */
    public function reportLegallyLate(ReportLegallyLate $event): void
    {
        $values = [...$this->report($event->year, $event->quarter), 'cga' => $this->organizationName($event->organizationId)];
        $this->recipients->notify($this->recipients->roles($event->organizationId, [Role::CgaManager]), 'report_legally_late', $values, '/cga/rapports');
        $this->recipients->notify($this->recipients->network('reports.network.view'), 'report_legally_late', $values, '/cncga/rapports');
    }

    /** Rapport transmis : au CNCGA. */
    public function reportTransmitted(ReportTransmitted $event): void
    {
        $values = [...$this->report($event->year, $event->quarter), 'cga' => $this->organizationName($event->organizationId)];
        $this->recipients->notify($this->recipients->network('reports.network.view'), 'report_transmitted', $values, '/cncga/rapports');
    }

    /** Campagne de clôture : rappel de pièces à l'adhérent (RG-OBL-11). */
    public function closingPieces(ClosingPiecesReminderDue $event): void
    {
        $item = $event->item;
        $this->recipients->notify(
            $this->recipients->memberAccesses($item->member_id),
            'closing_pieces_reminder',
            ['year' => $item->campaign->fiscal_year, 'due' => DocumentFormat::date($item->campaign->due(ClosingStep::Pieces))],
            '/adherent/cloture',
        );
    }

    /** Campagne de clôture : dossiers en retard, aux agents qui suivent la clôture (RG-OBL-11). */
    public function closingLate(ClosingStepsLate $event): void
    {
        $this->recipients->notify(
            $this->recipients->agents($event->campaign->organization_id, 'closing.manage'),
            'closing_steps_late',
            ['year' => $event->campaign->fiscal_year, 'count' => array_sum($event->late)],
            '/cga/cloture',
        );
    }

    private function paymentReviewed(string $memberId, int $amount, string $outcome): void
    {
        $this->recipients->notify(
            $this->recipients->memberAccesses($memberId),
            'payment_reviewed',
            ['amount' => DocumentFormat::amount($amount), 'outcome' => (string) __("notifications.outcomes.{$outcome}")],
            '/adherent/honoraires',
        );
    }

    private function radiation(string $memberId, string $organizationId, string $type, CarbonImmutable $effective): void
    {
        $values = ['member' => $this->memberName($memberId), 'date' => DocumentFormat::date($effective)];
        $this->recipients->notify($this->recipients->memberAccesses($memberId), $type, $values, '/adherent');
        $this->recipients->notify($this->recipients->roles($organizationId, [Role::CgaManager]), $type, $values, "/cga/adherents/{$memberId}");
    }

    /**
     * @return array{label: string}
     */
    private function report(int $year, int $quarter): array
    {
        return ['label' => (string) __('report.quarter_label', ['quarter' => $quarter, 'year' => $year])];
    }

    private function memberName(string $memberId): string
    {
        return (string) Tenancy::bypass(static fn (): ?string => Member::query()->whereKey($memberId)->value('company_name'), 'Notifications : nom du dossier.');
    }

    private function organizationName(string $organizationId): string
    {
        return (string) Organization::query()->whereKey($organizationId)->value('name');
    }
}
