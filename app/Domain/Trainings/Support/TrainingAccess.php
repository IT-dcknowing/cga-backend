<?php

namespace App\Domain\Trainings\Support;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lectures des formations hors du centre de l'espace actif (05-BACKEND-SCHEMA §13) : une formation est
 * cloisonnée à son organisateur, une inscription au CGA de la personne inscrite. Les CGA et les personnes
 * ciblées lisent les formations publiées du réseau, l'organisateur lit les inscriptions de ses formations,
 * chacun par un contournement nommé ; un brouillon n'est jamais vu hors de son organisateur.
 */
final class TrainingAccess
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Formation publiée (ou terminée, ou annulée) du CNCGA ou du centre de l'espace actif.
     *
     * @throws NotFoundHttpException identifiant inconnu, brouillon, formation d'un autre CGA
     */
    public function visible(string $id): Training
    {
        $training = Str::isUlid($id)
            ? Tenancy::bypass(fn (): ?Training => Training::query()
                ->with('organization')
                ->where('status', '<>', TrainingStatus::Draft)
                ->where(fn (Builder $query) => $query
                    ->where('organization_id', $this->context->organizationId())
                    ->orWhereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cncga)))
                ->find($id), 'Formations : formation du réseau lue par un CGA.')
            : null;

        return $training ?? throw new NotFoundHttpException;
    }

    /**
     * Formation publiée du réseau (CNCGA), vue d'un CGA (« Formations du réseau »).
     *
     * @throws NotFoundHttpException
     */
    public function network(string $id): Training
    {
        $training = $this->visible($id);

        return $training->organization->type === OrganizationType::Cncga ? $training : throw new NotFoundHttpException;
    }

    /**
     * Inscription à une formation de l'organisateur, quel que soit le CGA de la personne.
     *
     * @throws NotFoundHttpException
     */
    public static function registration(Training $training, string $id): TrainingRegistration
    {
        $registration = Str::isUlid($id)
            ? Tenancy::bypass(static fn (): ?TrainingRegistration => TrainingRegistration::query()->where('training_id', $training->id)->find($id), 'Formations : inscription lue par l\'organisateur.')
            : null;

        return $registration ?? throw new NotFoundHttpException;
    }

    /**
     * Inscriptions d'une formation, tous centres confondus (organisateur, places et liste d'attente).
     *
     * @return Builder<TrainingRegistration>
     */
    public static function registrations(Training $training): Builder
    {
        return TrainingRegistration::query()->where('training_id', $training->id);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function acrossCentres(callable $callback): mixed
    {
        return Tenancy::bypass($callback, 'Formations : inscriptions de plusieurs centres.');
    }

    /** Inscriptions qui occupent une place. */
    public static function seatsTaken(Training $training): int
    {
        return self::acrossCentres(static fn (): int => self::registrations($training)->where('status', RegistrationStatus::Registered)->count());
    }

    public static function seatsLeft(Training $training): ?int
    {
        return $training->capacity === null ? null : max(0, $training->capacity - self::seatsTaken($training));
    }

    /** Rang dans la liste d'attente (1 = première promue). */
    public static function waitlistRank(TrainingRegistration $registration): ?int
    {
        if ($registration->status !== RegistrationStatus::Waitlisted || $registration->waitlist_position === null) {
            return null;
        }

        return 1 + self::acrossCentres(static fn (): int => TrainingRegistration::query()
            ->where('training_id', $registration->training_id)
            ->where('status', RegistrationStatus::Waitlisted)
            ->where('waitlist_position', '<', $registration->waitlist_position)
            ->count());
    }
}
