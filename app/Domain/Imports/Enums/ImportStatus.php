<?php

namespace App\Domain\Imports\Enums;

use App\Support\Enums\HasLabel;

/**
 * Avancement d'un import : en file d'attente, en cours, terminé (rapport disponible), en échec.
 */
enum ImportStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
