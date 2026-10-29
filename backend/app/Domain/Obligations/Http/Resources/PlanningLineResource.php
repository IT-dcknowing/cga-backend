<?php

namespace App\Domain\Obligations\Http\Resources;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\ObligationCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne du planning des échéances (F-OBL-06) : date, obligation, nature, plateforme, nombre d'adhérents,
 * faits et à faire.
 *
 * @property array{due_date: string, obligation_type_id: string|null, label: string, category: string, kind: string, platform: string|null, members: int, done: int, open: int} $resource
 */
class PlanningLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $line = $this->resource;
        $category = ObligationCategory::from($line['category']);
        $kind = InstanceKind::from($line['kind']);

        return [
            'due_date' => $line['due_date'],
            'obligation_type_id' => $line['obligation_type_id'],
            'label' => $line['label'],
            'category' => $category->value,
            'category_label' => $category->label(),
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'platform' => $line['platform'],
            'members' => $line['members'],
            'done' => $line['done'],
            'open' => $line['open'],
        ];
    }
}
