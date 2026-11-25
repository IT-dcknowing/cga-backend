<?php

namespace App\Domain\Closing\Support;

use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use Carbon\CarbonImmutable;

/**
 * Dates des rappels de la campagne de clôture (RG-OBL-11) : au client pour ses pièces, aux jours
 * paramétrés avant l'étape 1 (J-15 et J-5 par défaut), puis tous les n jours de retard (7 par défaut)
 * tant que les pièces ne sont pas reçues ; aux agents, les dossiers en retard sur une étape.
 */
final class ClosingReminders
{
    public const BEFORE = 'before';

    public const LATE = 'late';

    /**
     * Rappel de pièces dû aujourd'hui : avant l'échéance, en retard, ou aucun.
     *
     * @param  list<int>  $offsets  jours avant l'étape 1, négatifs
     * @return self::BEFORE|self::LATE|null
     */
    public static function piecesReminder(CarbonImmutable $due, ?CarbonImmutable $piecesReceivedOn, CarbonImmutable $today, array $offsets, int $everyDays): ?string
    {
        if ($piecesReceivedOn !== null) {
            return null;
        }
        foreach ($offsets as $offset) {
            if ($today->isSameDay($due->addDays($offset))) {
                return self::BEFORE;
            }
        }
        $late = (int) $due->startOfDay()->diffInDays($today->startOfDay(), false);

        return $late > 0 && $late % max(1, $everyDays) === 0 ? self::LATE : null;
    }

    /** Étape en retard d'un dossier : la première à faire, si sa date est passée. */
    public static function lateStep(ClosingCampaign $campaign, ClosingCampaignItem $item, CarbonImmutable $today): ?ClosingStep
    {
        $step = $item->nextStep();

        return $step !== null && $campaign->due($step)->lessThan($today->startOfDay()) ? $step : null;
    }
}
