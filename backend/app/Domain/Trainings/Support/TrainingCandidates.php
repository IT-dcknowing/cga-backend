<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Inscrire des personnes » : personnes ciblées par la formation, dans le périmètre de l'espace actif — pour
 * le CNCGA, les agents des CGA, jamais un adhérent (RG-SEC-01) ; pour un CGA, ses agents et ses adhérents —,
 * cherchées sur le nom, au plus pagination.default, avec leur éventuelle inscription.
 */
final class TrainingCandidates
{
    public function __construct(
        private readonly TrainingAudiences $audiences,
        private readonly TenantContext $context,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Training $training, ?string $search): array
    {
        $network = $this->context->organizationType() === OrganizationType::Cncga;
        $organizationId = $this->context->organizationId();
        $limit = (int) $this->settings->get('pagination.default');

        $people = $this->audiences->memberships($training, static function (Builder $query) use ($network, $organizationId, $search, $limit): void {
            $network ? $query->whereNull('member_id') : $query->where('memberships.organization_id', $organizationId);
            if ($search !== null) {
                $pattern = '%'.addcslashes($search, '%_\\').'%';
                $query->whereHas('user', static fn (Builder $user) => $user->where(static fn (Builder $name) => $name->where('first_name', 'ilike', $pattern)->orWhere('last_name', 'ilike', $pattern)));
            }
            $query->limit($limit);
        });
        $registered = TrainingAccess::acrossCentres(static fn (): array => TrainingAccess::registrations($training)
            ->whereIn('status', RegistrationStatus::active())
            ->pluck('status', 'membership_id')
            ->map(static fn (mixed $status): string => $status instanceof RegistrationStatus ? $status->value : (string) $status)
            ->all());

        return $people
            ->sortBy(static fn (Membership $person): string => mb_strtolower($person->user->fullName()))
            ->map(static fn (Membership $person): array => [
                'membership_id' => $person->id,
                'name' => $person->user->fullName(),
                'kind' => $person->member_id === null ? 'agent' : 'member',
                'role' => $person->role->label(),
                'organization' => ['id' => $person->organization->id, 'name' => $person->organization->name],
                'company_name' => $network ? null : $person->member?->company_name,
                'registration_status' => $registered[$person->id] ?? null,
            ])
            ->values()
            ->all();
    }
}
