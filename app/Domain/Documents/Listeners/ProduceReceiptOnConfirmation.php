<?php

namespace App\Domain\Documents\Listeners;

use App\Domain\Audit\Enums\ActivityResult;
use App\Domain\Documents\Actions\ProduceReceipt;
use App\Domain\Payments\Events\PaymentConfirmed;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Logging\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Paiement confirmé (F-PAY-02, 03) : son reçu numéroté est produit, une fois la confirmation validée,
 * dans l'espace de celui qui l'a confirmée. Une fiche du centre incomplète (RG-ORG-04) ou un modèle
 * absent ne remet pas la confirmation en cause : le reçu se produira à la demande.
 */
class ProduceReceiptOnConfirmation implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ActivityLogger $activity,
        private readonly ProduceReceipt $produce,
    ) {}

    public function handle(PaymentConfirmed $event): void
    {
        if (! $this->context->has()) {
            return;
        }

        try {
            $this->produce->execute($event->payment, $this->context->membership());
        } catch (BusinessRuleException $exception) {
            $this->activity->record('documents.receipt_deferred', ActivityResult::BusinessError, [
                'subject_type' => $event->payment->getMorphClass(),
                'subject_id' => (string) $event->payment->getKey(),
                'error_code' => $exception->errorCode,
            ]);
        }
    }
}
