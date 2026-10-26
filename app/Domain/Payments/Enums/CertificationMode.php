<?php

namespace App\Domain\Payments\Enums;

use App\Support\Enums\HasLabel;

/**
 * Mode de certification FNE d'une organisation (RG-FNE-03) : manuel (défaut), API FNE de la DGI, Sell Flow.
 */
enum CertificationMode: string
{
    use HasLabel;

    case Manual = 'manual';
    case DgiApi = 'dgi_api';
    case SellFlow = 'sell_flow';
}
