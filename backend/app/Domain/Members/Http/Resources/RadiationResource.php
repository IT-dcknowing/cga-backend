<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Actions\CancelRadiation;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Radiation;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Radiation en vigueur d'un dossier (F-ADH-06, RG-ADH-17) : initiateur, motif, date d'effet, demande et
 * validation, issue, trimestre de l'état des résiliations, date limite d'annulation (RG-ADH-11).
 * L'observation et la pièce ne sont montrées qu'à ceux qui demandent ou valident les radiations
 * (manquements graves : donnée chiffrée).
 *
 * @property Radiation $resource
 */
class RadiationResource extends JsonResource
{
    public function __construct(Radiation $radiation, private readonly Member $member)
    {
        parent::__construct($radiation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $radiation = $this->resource;
        $confidential = Gate::allows('requestRadiation', $this->member) || Gate::allows('validateRadiation', $this->member);
        $deadline = app(CancelRadiation::class)->deadline($radiation);
        $requested = $radiation->status === RadiationStatus::Requested;

        return [
            'id' => $radiation->id,
            'status' => $radiation->status->value,
            'status_label' => $radiation->status->label(),
            'initiator' => $radiation->initiator->value,
            'initiator_label' => $radiation->initiator->label(),
            'reason' => $radiation->reason->value,
            'reason_label' => $radiation->reason->label(),
            'reason_other' => $radiation->reason_other,
            'effective_date' => $radiation->effective_date->toDateString(),
            'observation' => $this->when($confidential, fn (): string => $radiation->observation),
            'proof' => $this->when($confidential, fn (): ?array => $radiation->proof === null ? null : [
                'id' => $radiation->proof->id,
                'name' => $radiation->proof->original_name,
                'url' => app(StorageService::class)->temporaryUrl($radiation->proof),
            ]),
            'sp_request_reference' => $radiation->sp_request_reference,
            'requested_at' => $radiation->requested_at->toIso8601ZuluString(),
            'validated_at' => $radiation->validated_at?->toIso8601ZuluString(),
            'validation_reference' => $radiation->validation_reference,
            'outcome' => $radiation->outcome?->value,
            'outcome_label' => $radiation->outcome?->label(),
            'report_year' => $radiation->report_year,
            'report_quarter' => $radiation->report_quarter,
            'cancellable_until' => $deadline?->toIso8601ZuluString(),
            'can' => [
                'validate' => $requested && Gate::allows('validateRadiation', $this->member),
                'cancel' => ($deadline === null || now()->lessThanOrEqualTo($deadline)) && Gate::allows('cancelRadiation', $this->member),
            ],
        ];
    }
}
