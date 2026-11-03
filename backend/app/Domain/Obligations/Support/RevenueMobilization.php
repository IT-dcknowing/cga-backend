<?php

namespace App\Domain\Obligations\Support;

use App\Domain\Members\Enums\Portfolio;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Models\ObligationInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Mobilisation des recettes du portefeuille (onglet de `/cga/echeances`, 03-APPFLOW §4.3, étape 4.7) :
 * sur les échéances de paiement de la période (hors annulées), montants dus, payés et restants, taux
 * de recouvrement (payé / dû), par nature d'impôt et par contribuable. Le CGA est le référent des
 * montants dus (RG-OBL-08) : une échéance sans montant dû compte parmi les « montants à saisir ». Les
 * dossiers du portefeuille Cabinet n'y figurent qu'avec le droit de les voir (RG-PTF-02).
 */
final class RevenueMobilization
{
    /**
     * @return array{from: string, to: string, totals: array<string, int|float|null>, natures: list<array<string, mixed>>, members: list<array<string, mixed>>, members_total: int}
     */
    public function compute(CarbonImmutable $from, CarbonImmutable $to, bool $withCabinet, int $limit): array
    {
        $instances = ObligationInstance::query()
            ->with('member:id,company_name,membership_number,client_number,portfolio')
            ->where('kind', InstanceKind::Payment)
            ->where('status', '<>', InstanceStatus::Cancelled)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->when(! $withCabinet, static fn (Builder $query) => $query->whereHas(
                'member',
                static fn (Builder $member) => $member->where('portfolio', Portfolio::Cga),
            ))
            ->get();

        $natures = $instances
            ->groupBy(static fn (ObligationInstance $instance): string => $instance->paymentTaxNature()->value)
            ->map(static fn (Collection $lines): array => [
                'tax_nature' => $lines->first()->paymentTaxNature()->value,
                'tax_nature_label' => $lines->first()->paymentTaxNature()->label(),
                ...self::amounts($lines),
            ])
            ->sortByDesc('amount_due')
            ->values();

        $members = $instances
            ->groupBy('member_id')
            ->map(static fn (Collection $lines): array => [
                'member' => [
                    'id' => $lines->first()->member->id,
                    'company_name' => $lines->first()->member->company_name,
                    'membership_number' => $lines->first()->member->membership_number,
                    'client_number' => $lines->first()->member->client_number,
                ],
                ...self::amounts($lines),
            ])
            ->sortBy([['balance', 'desc'], ['member.company_name', 'asc']])
            ->values();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => self::amounts($instances),
            'natures' => $natures->all(),
            'members' => $members->take($limit)->all(),
            'members_total' => $members->count(),
        ];
    }

    /**
     * @param  Collection<int, ObligationInstance>  $lines
     * @return array{amount_due: int, amount_paid: int, balance: int, recovery_rate: float|null, amounts_to_enter: int}
     */
    private static function amounts(Collection $lines): array
    {
        $known = $lines->filter(static fn (ObligationInstance $instance): bool => $instance->amount_due !== null);
        $due = (int) $known->sum('amount_due');
        $paid = (int) $known->sum('amount_paid');

        return [
            'amount_due' => $due,
            'amount_paid' => $paid,
            'balance' => (int) $known->sum(static fn (ObligationInstance $instance): int => $instance->balance() ?? 0),
            'recovery_rate' => $due > 0 ? round($paid / $due * 100, 1) : null,
            'amounts_to_enter' => $lines->count() - $known->count(),
        ];
    }
}
