<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;

/**
 * Rassemble les données d'un type de document au moment de sa production (02-TRD §8.2) ; chaque type
 * désigne le sien dans config/documents.php (templates.{type}.composer). Le QR code de vérification
 * est ajouté par la chaîne de production.
 */
interface DocumentComposer
{
    public function compose(GeneratedDocument $document): DocumentContent;
}
