<?php

namespace App\Domain\Payments\Support;

use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Models\MemberCharge;

/**
 * Désignation d'une somme due sur un reçu ou une facture (F-PAY-02, F-PAY-05) : « Droit d'adhésion »,
 * « Honoraires du 01/05/2026 au 31/05/2026 », « Formation ».
 */
final class ChargeLabel
{
    public static function of(MemberCharge $charge): string
    {
        return (string) match ($charge->kind) {
            ChargeKind::Fees => __('documents.receipt_lines.fees', [
                'start' => DocumentFormat::shortDate($charge->period_start ?? $charge->due_date),
                'end' => DocumentFormat::shortDate($charge->period_end ?? $charge->due_date),
            ]),
            default => __('documents.receipt_lines.'.$charge->kind->value),
        };
    }
}
