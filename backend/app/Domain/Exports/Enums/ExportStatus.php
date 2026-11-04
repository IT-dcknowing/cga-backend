<?php

namespace App\Domain\Exports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Avancement d'un export : en file d'attente, en cours, prêt (fichier téléchargeable), en échec.
 */
enum ExportStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
