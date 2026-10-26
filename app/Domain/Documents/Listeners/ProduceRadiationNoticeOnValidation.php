<?php

namespace App\Domain\Documents\Listeners;

use App\Domain\Audit\Enums\ActivityResult;
use App\Domain\Documents\Actions\ProduceRadiationNotice;
use App\Domain\Members\Events\RadiationValidated;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Logging\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Radiation validée, effet 3 de F-ADH-06 : le courrier de notification au CDI de rattachement est
 * produit, une fois la validation enregistrée, dans l'espace du valideur. Une fiche du centre
 * incomplète (RG-ORG-04) ou un modèle absent ne remet pas la radiation en cause : le courrier se
 * produira à la demande.
 */
class ProduceRadiationNoticeOnValidation implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ActivityLogger $activity,
        private readonly ProduceRadiationNotice $produce,
    ) {}

    public function handle(RadiationValidated $event): void
    {
        if (! $this->context->has()) {
            return;
        }

        try {
            $this->produce->execute($event->radiation, $this->context->membership());
        } catch (BusinessRuleException $exception) {
            $this->activity->record('documents.radiation_notice_deferred', ActivityResult::BusinessError, [
                'subject_type' => $event->radiation->getMorphClass(),
                'subject_id' => (string) $event->radiation->getKey(),
                'error_code' => $exception->errorCode,
            ]);
        }
    }
}
