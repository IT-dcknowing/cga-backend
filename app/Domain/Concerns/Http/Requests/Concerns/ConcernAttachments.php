<?php

namespace App\Domain\Concerns\Http\Requests\Concerns;

use App\Support\Config\SettingsService;
use Illuminate\Http\UploadedFile;

/**
 * Pièces jointes d'un message de préoccupation (F-PRE-01, 02) : concerns.max_attachments fichiers au plus,
 * des types acceptés (files.allowed_types, contrôlés sur le contenu), files.max_upload_mb chacun.
 */
trait ConcernAttachments
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function attachmentRules(SettingsService $settings): array
    {
        return [
            'attachments' => ['sometimes', 'array', 'max:'.(int) $settings->get('concerns.max_attachments')],
            'attachments.*' => ['file', 'mimetypes:'.implode(',', (array) $settings->get('files.allowed_types')), 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values(array_filter((array) ($this->validated('attachments') ?? []), static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /** Longueur maximale d'un message. */
    protected function messageRule(): string
    {
        return 'max:'.(int) config('cga.concerns.message_max_length');
    }
}
