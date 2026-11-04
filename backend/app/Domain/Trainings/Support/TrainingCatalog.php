<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Http\Requests\ListTrainingsRequest;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Catalogues des formations hors de leur organisateur :
 * - « Formations du réseau » d'un CGA : formations du CNCGA publiées, terminées ou annulées ;
 * - « Formations ouvertes » d'une personne : formations publiées à venir de son CGA ou du CNCGA qui la ciblent
 *   (RG-FOR-01, 02), avec son inscription.
 */
final class TrainingCatalog
{
    public function __construct(private readonly TrainingAudiences $audiences) {}

    /**
     * Filtre « à venir » (début à partir de maintenant) ou « passées ».
     */
    public static function periodFilter(): AllowedFilter
    {
        return AllowedFilter::callback('period', static fn (Builder $query, mixed $period) => $period === ListTrainingsRequest::PERIOD_PAST
            ? $query->where('starts_at', '<', CarbonImmutable::now())
            : $query->where('starts_at', '>=', CarbonImmutable::now()));
    }

    /**
     * Formations du CNCGA hors brouillons (à appeler dans un contournement).
     *
     * @return Builder<Training>
     */
    public static function network(): Builder
    {
        return Training::query()
            ->with(['organization', 'audiences'])
            ->where('status', '<>', TrainingStatus::Draft)
            ->whereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cncga));
    }

    /**
     * Formations publiées à venir qui ciblent la personne, et celles où elle est inscrite.
     *
     * @return Collection<int, Training>
     */
    public function openTo(Membership $person): Collection
    {
        return Tenancy::bypass(function () use ($person): Collection {
            $registered = TrainingRegistration::query()->where('membership_id', $person->id)->where('status', '<>', RegistrationStatus::Cancelled)->pluck('training_id')->all();

            return Training::query()
                ->with(['organization', 'audiences'])
                ->where(static fn (Builder $scope) => $scope
                    ->where('organization_id', $person->organization_id)
                    ->orWhereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cncga)))
                ->where(static fn (Builder $open) => $open
                    ->where(static fn (Builder $upcoming) => $upcoming->where('status', TrainingStatus::Published)->where('starts_at', '>', CarbonImmutable::now()))
                    ->orWhereIn('id', $registered))
                ->orderBy('starts_at')
                ->get()
                ->filter(fn (Training $training): bool => in_array($training->id, $registered, true) || $this->audiences->targets($training, $person))
                ->values();
        }, 'Formations : formations ouvertes à une personne (CGA et réseau).');
    }

    /** Inscription en cours (ou dernière) de la personne à la formation. */
    public static function registrationOf(Training $training, Membership $person): ?TrainingRegistration
    {
        return Tenancy::bypass(static fn (): ?TrainingRegistration => TrainingRegistration::query()
            ->where('training_id', $training->id)
            ->where('membership_id', $person->id)
            ->latest()
            ->orderByDesc('id')
            ->first(), 'Formations : inscription d\'une personne.');
    }
}
