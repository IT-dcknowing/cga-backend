<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationStatusEvent as Event;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Abonnement d'un CGA (F-ORG-05) : non abonné, essai (subscription.trial_months, 3 mois offerts aux
 * pilotes), abonné, résilié. Un CGA non abonné n'a aucun accès (RG-ORG-06). La résiliation résilie le
 * CGA : ses données seront supprimées subscription.data_retention_months après (RG-ORG-07). Chaque
 * changement est inscrit dans l'historique, avec son motif. Rien n'est automatique : un essai échu
 * reste ouvert jusqu'à la décision du CNCGA (RG-COT-06).
 */
class ChangeSubscription
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RecordStatusEvent $record,
    ) {}

    /**
     * @throws BusinessRuleException subscription_unchanged, organization_terminated
     */
    public function execute(Organization $organization, SubscriptionStatus $status, ?string $reason, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $status, $reason, $actor): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->status === OrganizationStatus::Terminated) {
                throw new BusinessRuleException('organization_terminated');
            }
            if ($organization->subscription_status === $status) {
                throw new BusinessRuleException('subscription_unchanged');
            }

            $previous = $organization->subscription_status;
            $today = now()->startOfDay();

            match ($status) {
                SubscriptionStatus::Trial => $this->startTrial($organization, $today),
                SubscriptionStatus::Active => $organization->subscription_started_at = $today,
                SubscriptionStatus::Terminated => $this->terminate($organization, $today),
                SubscriptionStatus::None => null,
            };
            $organization->subscription_status = $status;
            $organization->save();

            $reason = $reason ?? __('organizations.subscription_changed', ['from' => $previous->label(), 'to' => $status->label()]);
            $this->record->execute($organization, Event::SubscriptionChanged, $reason, $actor);
            if ($status === SubscriptionStatus::Terminated) {
                $this->record->execute($organization, Event::Terminated, $reason, $actor);
            }

            return $organization;
        });
    }

    private function startTrial(Organization $organization, CarbonInterface $today): void
    {
        $organization->subscription_started_at = $today;
        $organization->trial_ends_at = $today->copy()->addMonths((int) $this->settings->get('subscription.trial_months'));
    }

    private function terminate(Organization $organization, CarbonInterface $today): void
    {
        $organization->subscription_ended_at = $today;
        $organization->data_deletion_due_at = $today->copy()->addMonths((int) $this->settings->get('subscription.data_retention_months'));
        $organization->status = OrganizationStatus::Terminated;
    }
}
