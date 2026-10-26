<?php

namespace App\Domain\Documents\Rules;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Support\TemplateInspector;
use App\Support\Files\StorageService;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Fichier d'une version de modèle (F-ADM-02) : un document Word pour un modèle DOCX, un classeur Excel
 * pour un modèle XLSX, d'après son contenu réel — jamais l'extension ni le type annoncé (02-TRD §6.7).
 */
class TemplateFileFormat implements DataAwareRule, ValidationRule
{
    public const MIME_TYPES = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(
        private readonly TemplateInspector $inspector,
        private readonly StorageService $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = DocumentType::tryFrom(is_string($this->data['type'] ?? null) ? $this->data['type'] : '');
        $format = $type === null ? null : $this->inspector->format($type);
        if ($format === null) {
            return;
        }

        $mime = $value instanceof UploadedFile ? $this->storage->detectMimeType($value->getContent()) : null;
        if ($mime !== self::MIME_TYPES[$format]) {
            $fail('validation.template_file_format')->translate(['format' => strtoupper($format)]);
        }
    }
}
