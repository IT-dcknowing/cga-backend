<?php

namespace App\Domain\Trainings\Http\Resources;

use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingAudience;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formation (F-FOR-01) : champs, organisateur, publics, places (inscrits, liste d'attente, en attente de
 * validation, places restantes) ; pour l'organisateur, le motif d'annulation et les actions permises ; pour la
 * personne ciblée, son inscription.
 *
 * @property Training $resource
 */
class TrainingResource extends JsonResource
{
    /** @var array<string, bool>|null */
    private ?array $abilities = null;

    private ?TrainingRegistration $registration = null;

    private bool $withRegistration = false;

    /**
     * @param  array<string, bool>  $abilities
     */
    public function withAbilities(array $abilities): self
    {
        $this->abilities = $abilities;

        return $this;
    }

    public function withRegistration(?TrainingRegistration $registration): self
    {
        $this->registration = $registration;
        $this->withRegistration = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $training = $this->resource;
        $counts = TrainingAccess::acrossCentres(static fn () => TrainingAccess::registrations($training)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status'));
        $registered = (int) $counts->get(RegistrationStatus::Registered->value, 0);
        $audiences = TrainingAccess::acrossCentres(static fn () => $training->relationLoaded('audiences') ? $training->audiences : $training->audiences()->get());

        $data = [
            'id' => $training->id,
            'title' => $training->title,
            'description' => $training->description,
            'format' => $training->format->value,
            'format_label' => $training->format->label(),
            'location' => $training->location,
            'online_url' => $training->online_url,
            'trainer' => $training->trainer,
            'starts_at' => $training->starts_at->toIso8601String(),
            'ends_at' => $training->ends_at->toIso8601String(),
            'capacity' => $training->capacity,
            'price' => $training->price,
            'requires_approval' => $training->requires_approval,
            'status' => $training->status->value,
            'status_label' => $training->status->label(),
            'published_at' => $training->published_at?->toIso8601String(),
            'cancellation_reason' => $training->cancellation_reason,
            'completed_at' => $training->completed_at?->toIso8601String(),
            'organizer' => $this->whenLoaded('organization', fn (): array => [
                'id' => $training->organization->id,
                'name' => $training->organization->name,
                'type' => $training->organization->type->value,
            ]),
            'audiences' => $audiences->map(static fn (TrainingAudience $audience): array => [
                'type' => $audience->audience->value,
                'label' => $audience->audience->label(),
                'regime' => $audience->regime?->value,
                'new_member_months' => $audience->new_member_months,
            ])->values()->all(),
            'seats' => [
                'registered' => $registered,
                'waitlisted' => (int) $counts->get(RegistrationStatus::Waitlisted->value, 0),
                'pending' => (int) $counts->get(RegistrationStatus::PendingApproval->value, 0),
                'left' => $training->capacity === null ? null : max(0, $training->capacity - $registered),
            ],
        ];

        if ($this->withRegistration) {
            $data['my_registration'] = $this->registration === null ? null : (new TrainingRegistrationResource($this->registration))->forAttendee()->toArray($request);
        }
        if ($this->abilities !== null) {
            $data['can'] = $this->abilities;
        }

        return $data;
    }
}
