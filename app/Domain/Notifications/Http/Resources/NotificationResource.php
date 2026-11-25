<?php

namespace App\Domain\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notification de la cloche (PRD M13) : type, titre, texte, lien vers l'écran concerné, lecture.
 *
 * @property DatabaseNotification $resource
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource->data;

        return [
            'id' => $this->resource->id,
            'type' => $data['type'] ?? $this->resource->type,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,
            'read_at' => $this->resource->read_at?->toIso8601ZuluString(),
            'created_at' => $this->resource->created_at?->toIso8601ZuluString(),
        ];
    }
}
