<?php

namespace App\Domain\Closing\Support;

use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tableau de suivi de la campagne (RG-OBL-11) et nombres du rapport trimestriel (RG-OBL-12) : par
 * étape, dossiers faits et en retard (filtrables par portefeuille et par agent) ; SMT et états
 * financiers établis (finalisation faite) et transmis (dépôt fait), comptés sur le seul portefeuille
 * CGA (scope regulatory).
 */
final class ClosingProgress
{
    /**
     * @param  Builder<ClosingCampaignItem>  $items  dossiers retenus par les filtres
     * @return array{items: int, steps: list<array{code: string, done: int, late: int}>}
     */
    public static function board(ClosingCampaign $campaign, Builder $items, CarbonImmutable $today): array
    {
        $steps = [];
        $previous = null;
        foreach (ClosingStep::cases() as $step) {
            $done = (clone $items)->whereNotNull($step->column())->count();
            $late = $campaign->due($step)->lessThan($today->startOfDay())
                ? (clone $items)->whereNull($step->column())->when($previous !== null, static fn (Builder $query) => $query->whereNotNull($previous->column()))->count()
                : 0;
            $steps[] = ['code' => $step->value, 'done' => $done, 'late' => $late];
            $previous = $step;
        }

        return ['items' => (clone $items)->count(), 'steps' => $steps];
    }

    /**
     * Nombres proposés pour la section 4 du rapport trimestriel (RG-OBL-12), modifiables avant génération.
     *
     * @return array{smt_established: int, smt_transmitted: int, financial_statements_established: int, financial_statements_transmitted: int}
     */
    public static function report(ClosingCampaign $campaign): array
    {
        $count = static fn (ClosingDeliverable $deliverable, ClosingStep $step): int => ClosingCampaignItem::query()
            ->regulatory()
            ->where('campaign_id', $campaign->id)
            ->where('deliverable', $deliverable)
            ->whereNotNull($step->column())
            ->count();

        return [
            'smt_established' => $count(ClosingDeliverable::Smt, ClosingStep::Finalisation),
            'smt_transmitted' => $count(ClosingDeliverable::Smt, ClosingStep::Filing),
            'financial_statements_established' => $count(ClosingDeliverable::FinancialStatements, ClosingStep::Finalisation),
            'financial_statements_transmitted' => $count(ClosingDeliverable::FinancialStatements, ClosingStep::Filing),
        ];
    }

    /**
     * Dossiers dont la prochaine étape est `step` (filtre « étape ») ; tous faits pour null.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $items
     * @return Builder<TModel>
     */
    public static function atStep(Builder $items, ?ClosingStep $step): Builder
    {
        if ($step === null) {
            return $items->whereNotNull(ClosingStep::Filing->column());
        }
        $previous = null;
        foreach (ClosingStep::cases() as $case) {
            if ($case === $step) {
                break;
            }
            $previous = $case;
        }

        return $items->whereNull($step->column())->when($previous !== null, static fn (Builder $query) => $query->whereNotNull($previous->column()));
    }

    /**
     * Dossiers en retard : leur prochaine étape a une date passée.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $items
     * @return Builder<TModel>
     */
    public static function late(ClosingCampaign $campaign, Builder $items, CarbonImmutable $today): Builder
    {
        $passed = array_values(array_filter(ClosingStep::cases(), static fn (ClosingStep $step): bool => $campaign->due($step)->lessThan($today->startOfDay())));
        if ($passed === []) {
            return $items->whereKey([]);
        }

        return $items->where(static function (Builder $query) use ($passed): void {
            foreach ($passed as $step) {
                $query->orWhere(static fn (Builder $late) => self::atStep($late, $step));
            }
        });
    }
}
