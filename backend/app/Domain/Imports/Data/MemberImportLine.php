<?php

namespace App\Domain\Imports\Data;

use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Members\Enums\Portfolio;
use Carbon\CarbonImmutable;

/**
 * Ligne d'un portefeuille à reprendre, analysée (F-IMP-02, RG-IMP-02) : son sort attendu (importée
 * active, en brouillon avec ce qui manque, rejetée avec ses motifs) et la fiche à créer.
 */
final readonly class MemberImportLine
{
    /**
     * @param  list<string>  $messages  champs manquants (brouillon) ou motifs de rejet
     * @param  array<string, mixed>  $attributes  champs de la fiche
     */
    public function __construct(
        public ImportRowStatus $status,
        public array $messages,
        public ?string $label,
        public ?Portfolio $portfolio,
        public ?string $ncc,
        public ?string $legacyNumber,
        public ?CarbonImmutable $adhesionDate,
        public array $attributes,
    ) {}

    /**
     * La ligne sera importée comme dossier actif, numéroté à la fin du lot.
     */
    public function active(): bool
    {
        return $this->status === ImportRowStatus::Imported;
    }

    /**
     * Même ligne, rejetée pour ce motif (doublon dans le fichier…).
     */
    public function rejectedFor(string $reason): self
    {
        return new self(ImportRowStatus::Rejected, [$reason], $this->label, $this->portfolio, $this->ncc, $this->legacyNumber, $this->adhesionDate, []);
    }
}
