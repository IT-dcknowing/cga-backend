<?php

namespace App\Domain\Imports\Data;

use App\Domain\Imports\Enums\ImportRowStatus;

/**
 * Sort d'une ligne (RG-IMP-02) : importée, importée à compléter avec les champs manquants, ou
 * rejetée avec ses motifs ; l'objet créé et le libellé de la ligne pour le rapport.
 */
final readonly class RowOutcome
{
    /**
     * @param  list<string>  $messages
     */
    private function __construct(
        public ImportRowStatus $status,
        public array $messages,
        public ?string $label,
        public ?string $createdEntityId,
    ) {}

    public static function imported(string $createdEntityId, ?string $label): self
    {
        return new self(ImportRowStatus::Imported, [], $label, $createdEntityId);
    }

    /**
     * @param  non-empty-list<string>  $gaps  champs manquants ou à vérifier
     */
    public static function draft(string $createdEntityId, ?string $label, array $gaps): self
    {
        return new self(ImportRowStatus::Draft, $gaps, $label, $createdEntityId);
    }

    /**
     * @param  non-empty-list<string>  $reasons
     */
    public static function rejected(?string $label, array $reasons): self
    {
        return new self(ImportRowStatus::Rejected, $reasons, $label, null);
    }
}
