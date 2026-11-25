<?php

namespace App\Domain\Announcements\Http\Resources;

use App\Domain\Announcements\Models\Announcement;
use App\Domain\Announcements\Models\AnnouncementTarget;
use App\Support\Files\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Annonce (PRD M12) : titre, texte, pièce jointe (lien temporaire signé), auteur, date ; pour son auteur, la
 * cible, le filtre, les CGA choisis, l'e-mail, le nombre de destinataires et de lectures ; pour un destinataire,
 * s'il l'a lue.
 *
 * @property Announcement $resource
 */
class AnnouncementResource extends JsonResource
{
    private bool $author = false;

    public function forAuthor(): self
    {
        $this->author = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $announcement = $this->resource;
        $data = [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'published_at' => $announcement->published_at->toIso8601String(),
            'from' => $this->whenLoaded('organization', fn (): array => ['name' => $announcement->organization->name, 'type' => $announcement->organization->type->value]),
            'attachment' => $this->whenLoaded('attachment', fn (): ?array => $announcement->attachment === null ? null : [
                'name' => $announcement->attachment->original_name,
                'mime_type' => $announcement->attachment->mime_type,
                'url' => app(StorageService::class)->temporaryUrl($announcement->attachment),
            ]),
        ];

        if (! $this->author) {
            return [...$data, 'read' => (bool) ($announcement->getAttribute('read') ?? false)];
        }

        return [
            ...$data,
            'audience' => $announcement->audience->value,
            'audience_label' => $announcement->audience->label(),
            'member_filter' => $announcement->member_filter,
            'targets' => $this->whenLoaded('targets', fn (): array => $announcement->targets->map(static fn (AnnouncementTarget $target): array => [
                'id' => $target->target_organization_id,
                'name' => $target->organization->name,
            ])->values()->all()),
            'send_email' => $announcement->send_email,
            'recipients_count' => $announcement->recipients_count,
            'reads_count' => (int) ($announcement->getAttribute('reads_count') ?? 0),
        ];
    }
}
