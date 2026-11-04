<?php

namespace App\Domain\Trainings\Http\Resources;

use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inscription à une formation : statut, rang en liste d'attente, personne (nom, agent ou adhérent, CGA ;
 * l'entreprise de l'adhérent n'est donnée qu'à son CGA, jamais au CNCGA, RG-SEC-01), paiement et reçu,
 * présence, attestation. Pour la personne elle-même : la formation et son évaluation.
 *
 * @property TrainingRegistration $resource
 */
class TrainingRegistrationResource extends JsonResource
{
    private bool $attendee = false;

    private bool $withCompany = false;

    public function forAttendee(): self
    {
        $this->attendee = true;

        return $this;
    }

    /** Le CGA de la personne voit l'entreprise d'un adhérent. */
    public function withCompany(bool $withCompany): self
    {
        $this->withCompany = $withCompany;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registration = $this->resource;
        TrainingAccess::acrossCentres(static fn () => $registration->loadMissing(['user', 'membership.organization', 'member', 'receipt.pdf', 'certificate.pdf', 'training.organization']));

        $data = [
            'id' => $registration->id,
            'status' => $registration->status->value,
            'status_label' => $registration->status->label(),
            'waitlist_rank' => TrainingAccess::waitlistRank($registration),
            'rejection_reason' => $registration->rejection_reason,
            'registered_at' => $registration->created_at->toIso8601String(),
            'person' => [
                'membership_id' => $registration->membership_id,
                'name' => $registration->user->fullName(),
                'kind' => $registration->member_id === null ? 'agent' : 'member',
                'organization' => ['id' => $registration->membership->organization->id, 'name' => $registration->membership->organization->name],
                'company_name' => $this->withCompany || $this->attendee ? $registration->member?->company_name : null,
            ],
            'payment_status' => $registration->payment_status->value,
            'payment_status_label' => $registration->payment_status->label(),
            'paid_on' => $registration->paid_on?->toDateString(),
            'payment_method' => $registration->payment_method?->value,
            'receipt_number' => $registration->receipt_number,
            'receipt_url' => self::url($registration->receipt),
            'attendance' => $registration->attendance?->value,
            'certificate_url' => self::url($registration->certificate),
        ];

        if ($this->attendee) {
            $data['training'] = [
                'id' => $registration->training->id,
                'title' => $registration->training->title,
                'starts_at' => $registration->training->starts_at->toIso8601String(),
                'status' => $registration->training->status->value,
                'organizer' => $registration->training->organization->name,
            ];
            $data['evaluation'] = $registration->evaluated_at === null ? null : ['score' => $registration->evaluation_score, 'comment' => $registration->evaluation_comment];
        }

        return $data;
    }

    private static function url(?GeneratedDocument $document): ?string
    {
        return $document?->pdf === null ? null : app(StorageService::class)->temporaryUrl($document->pdf);
    }
}
