<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Models\MemberRegimeChange;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de l'historique des régimes d'un dossier (F-ADH-05) : régime, date d'effet, motif, justificatif
 * et auteur.
 *
 * @property MemberRegimeChange $resource
 */
class RegimeChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $change = $this->resource;

        return [
            'id' => $change->id,
            'regime' => $change->regime->value,
            'regime_label' => $change->regime->label(),
            'effective_date' => $change->effective_date->toDateString(),
            'reason' => $change->reason,
            'justification' => $change->justification === null ? null : [
                'id' => $change->justification->id,
                'name' => $change->justification->original_name,
                'url' => app(StorageService::class)->temporaryUrl($change->justification),
            ],
            'author' => $change->author?->fullName(),
            'created_at' => $change->created_at?->toIso8601ZuluString(),
        ];
    }
}
