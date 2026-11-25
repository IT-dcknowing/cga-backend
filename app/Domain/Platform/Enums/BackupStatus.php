<?php

namespace App\Domain\Platform\Enums;

use App\Support\Enums\HasLabel;

/**
 * Résultat d'une sauvegarde : en cours, réussie, en échec.
 */
enum BackupStatus: string
{
    use HasLabel;

    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
