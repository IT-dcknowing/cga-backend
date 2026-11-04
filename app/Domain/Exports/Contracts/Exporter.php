<?php

namespace App\Domain\Exports\Contracts;

use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;

/**
 * Producteur des feuilles d'un export (F-EXP-01) : lit dans l'espace et avec les droits de la personne qui exporte
 * (le Job tourne dans son espace), applique la période et les filtres validés, et rend des valeurs simples.
 */
interface Exporter
{
    /**
     * Règles des filtres propres à cette liste (clés de `filters`), validées à la demande.
     *
     * @return array<string, list<mixed>>
     */
    public function filterRules(): array;

    /**
     * @return iterable<ExportSheet>
     */
    public function sheets(ExportCriteria $criteria): iterable;
}
