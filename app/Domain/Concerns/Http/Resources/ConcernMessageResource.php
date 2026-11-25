<?php

namespace App\Domain\Concerns\Http\Resources;

use App\Domain\Concerns\Models\ConcernMessage;
use App\Domain\Concerns\Models\ConcernMessageFile;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Message du fil d'une préoccupation (ConversationThread) : texte, côté et nom de l'auteur, message
 * système, note interne (signalée à qui traite la demande, jamais donnée au demandeur), pièces jointes par
 * lien temporaire signé. Un message copié de la demande d'origine (F-PRE-03) ne nomme pas son auteur : son
 * côté seulement (« Adhérent », « CGA »), RG-SEC-01.
 *
 * @property ConcernMessage $resource
 */
class ConcernMessageResource extends JsonResource
{
    private bool $handler = false;

    public function forHandler(bool $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->resource;
        $storage = app(StorageService::class);

        $data = [
            'id' => $message->id,
            'body' => $message->body,
            'side' => $message->author_side->value,
            'author' => [
                'name' => $message->copied_side !== null
                    ? (string) __("concerns.copied_authors.{$message->copied_side->value}")
                    : $message->author->fullName(),
            ],
            'system' => $message->is_system,
            'copied' => $message->copied_side !== null,
            'created_at' => $message->created_at->toIso8601String(),
            'attachments' => $message->attachments->map(static fn (ConcernMessageFile $attachment): array => [
                'id' => $attachment->file->id,
                'name' => $attachment->file->original_name,
                'mime_type' => $attachment->file->mime_type,
                'size_bytes' => $attachment->file->size_bytes,
                'url' => $storage->temporaryUrl($attachment->file),
            ])->values()->all(),
        ];

        if ($this->handler) {
            $data['internal'] = $message->is_internal;
        }

        return $data;
    }
}
