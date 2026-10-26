<?php

namespace App\Domain\Documents\Data;

use App\Domain\Documents\Enums\DocumentType;
use Carbon\CarbonInterface;

/**
 * Document à émettre (F-DOC-01) : type, titre, dossier, numéro, document remplacé (régénération,
 * duplicata), validité, exercice, visibilité par l'adhérent et objet d'origine. La version suit les
 * documents déjà émis sous le même numéro.
 */
final readonly class DocumentRequest
{
    public function __construct(
        public DocumentType $type,
        public string $title,
        public ?string $memberId = null,
        public ?string $number = null,
        public ?string $replacesDocumentId = null,
        public ?CarbonInterface $validUntil = null,
        public ?int $fiscalYear = null,
        public bool $visibleToMember = true,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
    ) {}
}
