<?php

namespace App\Domain\Organizations\Enums;

use App\Support\Enums\HasLabel;

/**
 * Image de la fiche du CGA utilisée dans ses documents (F-ORG-03, RG-DOC-01, 02).
 */
enum CgaImageKind: string
{
    use HasLabel;

    case Logo = 'logo';
    case Signature = 'signature';
    case Stamp = 'stamp';

    /**
     * Colonne de la fiche qui désigne le fichier.
     */
    public function column(): string
    {
        return match ($this) {
            self::Logo => 'logo_file_id',
            self::Signature => 'signature_file_id',
            self::Stamp => 'stamp_file_id',
        };
    }
}
