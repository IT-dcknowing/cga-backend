<?php

namespace App\Domain\Trainings\Composers;

use App\Domain\Documents\Composers\CgaIdentity;
use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;

/**
 * Reçu du paiement d'une formation (RG-FOR-06, modèle training_receipt) : organisateur, personne inscrite,
 * formation et dates, montant en chiffres et en lettres, date, mode et référence du paiement.
 */
class TrainingReceiptComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $issuer = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $registration = TrainingAccess::acrossCentres(static fn (): TrainingRegistration => TrainingRegistration::query()
            ->with(['training', 'user'])
            ->findOrFail($document->source_id));
        $facts = TrainingFacts::values($registration->training);

        return new DocumentContent([
            ...$this->identity->values($issuer),
            'receipt_number' => DocumentFormat::text($registration->receipt_number),
            'payer_name' => $registration->user->fullName(),
            'training_title' => $facts['training_title'],
            'training_dates' => $facts['training_dates'],
            'payment_amount' => DocumentFormat::amount($registration->training->price),
            'payment_amount_words' => DocumentFormat::amountInWords($registration->training->price),
            'payment_date' => DocumentFormat::date($registration->paid_on),
            'payment_method' => (string) $registration->payment_method?->label(),
            'payment_reference' => DocumentFormat::text($registration->payment_reference),
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($issuer));
    }
}
