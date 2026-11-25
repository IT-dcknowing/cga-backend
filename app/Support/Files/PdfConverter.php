<?php

namespace App\Support\Files;

use App\Support\Http\Exceptions\ExternalServiceException;

/**
 * Conversion d'un document bureautique (DOCX, XLSX) en PDF (02-TRD §8.2) : pilote « libreoffice » au
 * démarrage, « gotenberg » possible plus tard (PDF_CONVERTER), sans changer le code appelant.
 */
interface PdfConverter
{
    /**
     * @param  string  $sourcePath  fichier à convertir, sur le disque local du serveur
     * @return string contenu du PDF
     *
     * @throws ExternalServiceException conversion impossible ou trop longue (pdf.timeout_seconds)
     */
    public function convert(string $sourcePath): string;
}
