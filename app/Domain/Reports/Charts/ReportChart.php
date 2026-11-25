<?php

namespace App\Domain\Reports\Charts;

use InvalidArgumentException;

/**
 * Un graphique du rapport modernisé (02-TRD §8.4) : type, titre, catégories et séries de valeurs,
 * calculés par le service du rapport et rendus en graphique natif du document (décision T-21).
 */
final readonly class ReportChart
{
    public const TYPES = ['pie', 'column', 'bar', 'line'];

    /**
     * @param  list<string>  $categories
     * @param  array<string, list<int|float>>  $series  nom de la série => une valeur par catégorie
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $categories,
        public array $series,
        public ?string $valueAxisTitle = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Type de graphique inconnu : {$type}");
        }
        if ($categories === [] || $series === []) {
            throw new InvalidArgumentException('Un graphique a au moins une catégorie et une série.');
        }
        if ($type === 'pie' && count($series) > 1) {
            throw new InvalidArgumentException('Un graphique en secteurs n\'a qu\'une série.');
        }
        foreach ($series as $name => $values) {
            if (count($values) !== count($categories)) {
                throw new InvalidArgumentException("La série « {$name} » n'a pas une valeur par catégorie.");
            }
        }
    }
}
