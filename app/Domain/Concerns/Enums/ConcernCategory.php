<?php

namespace App\Domain\Concerns\Enums;

use App\Support\Enums\HasLabel;

/**
 * Catégorie d'une préoccupation (F-PRE-01), plus « Pièces de clôture », que crée « Déposer des pièces »
 * de l'espace adhérent (03-APPFLOW §5, décision 0012).
 */
enum ConcernCategory: string
{
    use HasLabel;

    case Tax = 'tax';
    case Accounting = 'accounting';
    case Legal = 'legal';
    case Social = 'social';
    case Training = 'training';
    case Administrative = 'administrative';
    case PublicPlatforms = 'public_platforms';
    case DocumentRequest = 'document_request';
    case InfoUpdate = 'info_update';
    case ClosingPieces = 'closing_pieces';
    case Other = 'other';
}
