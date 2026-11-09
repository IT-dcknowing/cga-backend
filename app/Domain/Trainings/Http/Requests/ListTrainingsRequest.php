<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use App\Domain\Trainings\Models\Training;
use App\Support\Http\ListRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listes des formations, triées par date de début :
 * - GET /{network|cga}/trainings : celles de l'organisateur (filtres statut, à venir / passées) ;
 * - GET /cga/network-trainings : « Formations du réseau » vues d'un CGA ;
 * - GET /me/trainings : formations ouvertes à la personne de l'espace actif (CGA et réseau).
 */
class ListTrainingsRequest extends ListRequest
{
    use OrganizerTraining;

    public const PERIOD_UPCOMING = 'upcoming';

    public const PERIOD_PAST = 'past';

    public function authorize(): bool
    {
        return match (true) {
            $this->routeIs('me.*') => Gate::allows('attend', Training::class),
            $this->routeIs('cga.network-trainings.*') => Gate::allows('browseNetwork', Training::class),
            default => $this->organizerSpace() && Gate::allows('organize', Training::class),
        };
    }

    protected function filters(): array
    {
        return [
            'status' => [Rule::enum(TrainingStatus::class)],
            'period' => [Rule::in([self::PERIOD_UPCOMING, self::PERIOD_PAST])],
        ];
    }

    public function sorts(): array
    {
        return ['starts_at', 'created_at'];
    }
}
