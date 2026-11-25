<?php

namespace App\Domain\Documents\Http\Resources;

use App\Domain\Documents\Models\AttestationBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Blocage de l'attestation d'un adhérent (RG-DOC-10) : origine, motif, auteur et date ; déblocage,
 * son auteur, sa date et son motif.
 *
 * @property AttestationBlock $resource
 */
class AttestationBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $block = $this->resource;

        return [
            'id' => $block->id,
            'active' => $block->unblocked_at === null,
            'trigger' => $block->trigger->value,
            'trigger_label' => $block->trigger->label(),
            'reason' => $block->reason,
            'blocked_at' => $block->blocked_at->toIso8601ZuluString(),
            'blocked_by' => $this->whenLoaded('blocker', fn (): array => ['id' => $block->blocker->id, 'name' => $block->blocker->fullName()]),
            'unblocked_at' => $block->unblocked_at?->toIso8601ZuluString(),
            'unblocked_by' => $this->whenLoaded('unblocker', fn (): ?array => $block->unblocker === null ? null : ['id' => $block->unblocker->id, 'name' => $block->unblocker->fullName()]),
            'unblock_reason' => $block->unblock_reason,
        ];
    }
}
