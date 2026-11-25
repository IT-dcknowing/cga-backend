<?php

namespace App\Domain\Documents\Data;

use App\Domain\Reports\Charts\ReportChart;

/**
 * Données d'un document à produire (02-TRD §8.2), rassemblées par le composeur de son type : valeurs
 * des variables ${…}, images (contenu PNG ou JPEG) et lignes répétées (cloneRow).
 */
final readonly class DocumentContent
{
    /**
     * @param  array<string, string>  $values  variable => texte
     * @param  array<string, string>  $images  variable => contenu de l'image
     * @param  array<string, list<array<string, string>>>  $rows  variable de ligne => lignes
     * @param  array<string, ReportChart>  $charts  variable => graphique natif (rapport modernisé, T-21)
     */
    public function __construct(
        public array $values,
        public array $images = [],
        public array $rows = [],
        public array $charts = [],
    ) {}
}
