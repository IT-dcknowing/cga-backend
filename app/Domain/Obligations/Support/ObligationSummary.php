<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\ObligationInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Récapitulatif des blocs Impôts, CNPS, CMU et Autres administrations (F-OBL-04, F-OBL-05) : « Total dû »
 * = somme des restes à payer des échéances affichées, échues et à venir (RG-OBL-08), hors échéances
 * annulées ; s'y ajoutent les montants dus encore à saisir et les échéances à traiter, dont les échues.
 */
final class ObligationSummary
{
    /**
     * Statuts d'une échéance encore à traiter.
     *
     * @return list<InstanceStatus>
     */
    public static function open(): array
    {
        return [InstanceStatus::Todo, InstanceStatus::ToPay, InstanceStatus::PartiallyPaid, InstanceStatus::NotEstablished, InstanceStatus::InProgress];
    }

    /**
     * @param  Collection<int, ObligationInstance>  $instances
     * @return array<string, array{total_due: int, amounts_to_enter: int, open: int, overdue: int}>
     */
    public static function byCategory(Collection $instances, CarbonImmutable $today): array
    {
        $summary = [];
        foreach (ObligationCategory::cases() as $category) {
            $lines = $instances->filter(static fn (ObligationInstance $instance): bool => $instance->category === $category
                && $instance->status !== InstanceStatus::Cancelled);
            $payments = $lines->filter(static fn (ObligationInstance $instance): bool => $instance->kind === InstanceKind::Payment);
            $open = $lines->filter(static fn (ObligationInstance $instance): bool => in_array($instance->status, self::open(), true));

            $summary[$category->value] = [
                'total_due' => (int) $payments->sum(static fn (ObligationInstance $instance): int => $instance->balance() ?? 0),
                'amounts_to_enter' => $payments->filter(static fn (ObligationInstance $instance): bool => $instance->amount_due === null)->count(),
                'open' => $open->count(),
                'overdue' => $open->filter(static fn (ObligationInstance $instance): bool => $instance->due_date->lessThan($today))->count(),
            ];
        }

        return $summary;
    }
}
