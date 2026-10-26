<?php

namespace App\Domain\Members\Data;

use Carbon\CarbonImmutable;

/**
 * Entrée de l'historique d'un dossier (MemberHistory) : une ligne du journal des modifications, déjà
 * filtrée selon les droits de l'utilisateur.
 */
final readonly class MemberHistoryEntry
{
    /**
     * @param  list<array{field: string, before: mixed, after: mixed}>  $changes
     * @param  array{name: string, url: string}|null  $justification
     */
    public function __construct(
        public int $id,
        public CarbonImmutable $occurredAt,
        public string $event,
        public string $subject,
        public ?string $actor,
        public array $changes,
        public ?array $justification,
        public ?string $source,
    ) {}
}
