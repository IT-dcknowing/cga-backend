<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingPaymentStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\NumberFormatter;
use App\Support\Numbering\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrer le paiement d'une formation payante (RG-FOR-06), encaissé hors plateforme, par
 * l'organisateur : date, mode, référence ; l'inscription passe « payée » et un reçu numéroté
 * (numbering.training_receipt.pattern, séquence de l'organisateur par année) est produit.
 */
class RecordTrainingPayment
{
    private const SEQUENCE = 'training_receipt';

    public function __construct(
        private readonly IssueDocument $documents,
        private readonly SequenceService $sequences,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @throws BusinessRuleException training_payment_not_due
     */
    public function execute(TrainingRegistration $registration, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference, Membership $actor): TrainingRegistration
    {
        return TrainingAccess::acrossCentres(fn (): TrainingRegistration => DB::transaction(function () use ($registration, $paidOn, $method, $reference, $actor): TrainingRegistration {
            $registration = TrainingRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($registration->payment_status !== TrainingPaymentStatus::ToPay || $registration->status !== RegistrationStatus::Registered) {
                throw new BusinessRuleException('training_payment_not_due');
            }
            $training = Training::query()->with('organization')->findOrFail($registration->training_id);
            $year = $paidOn->year;

            $registration->payment_status = TrainingPaymentStatus::Paid;
            $registration->paid_on = $paidOn;
            $registration->payment_method = $method;
            $registration->payment_reference = $reference;
            $registration->receipt_number = NumberFormatter::format(
                (string) $this->settings->get('numbering.training_receipt.pattern'),
                $this->sequences->next($training->organization_id, self::SEQUENCE, $year),
                $year,
                $training->organization->numbering_abbreviation,
            );
            $registration->save();

            $receipt = $this->documents->execute(new DocumentRequest(
                type: DocumentType::TrainingReceipt,
                title: (string) __('trainings.receipt_title', ['title' => $training->title]),
                number: $registration->receipt_number,
                fiscalYear: $year,
                visibleToMember: false,
                sourceType: 'training_registration',
                sourceId: $registration->id,
            ), $actor);
            $registration->receipt_document_id = $receipt->id;
            $registration->save();

            return $registration;
        }));
    }
}
