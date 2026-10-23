<?php

namespace App\Domain\Documents\Enums;

use App\Support\Enums\HasLabel;

/**
 * Catégorie d'un document déposé par le CGA dans l'espace de l'adhérent (F-DOC-05) : états financiers
 * visés, attestations diverses, autre.
 */
enum UploadedDocumentCategory: string
{
    use HasLabel;

    case FinancialStatements = 'financial_statements';
    case Certificate = 'certificate';
    case Other = 'other';
}
