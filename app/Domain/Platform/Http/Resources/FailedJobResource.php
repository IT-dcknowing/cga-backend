<?php

namespace App\Domain\Platform\Http\Resources;

use App\Domain\Platform\Models\FailedJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Travail en échec affiché à l'administrateur : file, nom du travail, date, première ligne de l'erreur. Jamais le
 * contenu du travail ni la pile d'appels.
 *
 * @mixin FailedJob
 */
class FailedJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'queue' => $this->queue,
            'job' => $this->jobName(),
            'failed_at' => $this->failed_at->toIso8601String(),
            'error' => $this->errorSummary(),
        ];
    }
}
