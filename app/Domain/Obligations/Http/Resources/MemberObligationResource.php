<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Enums\MemberObligationStatus;
use App\Domain\Obligations\Models\MemberObligation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Obligation attribuée à un dossier (F-OBL-02) : proposée, active ou retirée, son origine, sa période
 * et l'obligation du catalogue ; `can` dit ce que l'agent peut en faire selon le serveur.
 *
 * @mixin MemberObligation
 */
class MemberObligationResource extends JsonResource
{
    /**
     * Droit de gérer les obligations du dossier, calculé une fois par la liste.
     */
    public bool $manages = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MemberObligation $obligation */
        $obligation = $this->resource;
        $open = $obligation->status !== MemberObligationStatus::Removed;

        return [
            'id' => $obligation->id,
            'status' => $obligation->status->value,
            'status_label' => $obligation->status->label(),
            'source' => $obligation->source->value,
            'source_label' => $obligation->source->label(),
            'start_date' => $obligation->start_date->toDateString(),
            'end_date' => $obligation->end_date?->toDateString(),
            'confirmed_at' => $obligation->confirmed_at?->toIso8601ZuluString(),
            'type' => new ObligationTypeResource($obligation->type),
            'can' => [
                'confirm' => $this->manages && $obligation->status === MemberObligationStatus::Suggested,
                'remove' => $this->manages && $open,
            ],
        ];
    }

    public function managedBy(bool $manages): static
    {
        $this->manages = $manages;

        return $this;
    }
}
