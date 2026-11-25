<?php

namespace App\Domain\Statistics\Support;

use Carbon\CarbonImmutable;

/**
 * Période d'un tableau de bord (F-DSH-04) : jour, mois (par défaut), année civile ou période personnalisée, bornes
 * comprises, et la même période de l'année précédente (N-1), pour la variation de chaque indicateur. La fin
 * effective (`until`) s'arrête au jour même : les stocks d'une période en cours sont ceux d'aujourd'hui.
 */
final readonly class StatPeriod
{
    public const DAY = 'day';

    public const MONTH = 'month';

    public const YEAR = 'year';

    public const CUSTOM = 'custom';

    public const TYPES = [self::DAY, self::MONTH, self::YEAR, self::CUSTOM];

    private function __construct(
        public string $type,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Période d'un jour de référence (jour, mois, année) ou entre deux dates (personnalisée).
     */
    public static function of(string $type, CarbonImmutable $date, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): self
    {
        $date = $date->startOfDay();

        return match ($type) {
            self::DAY => new self($type, $date, $date),
            self::YEAR => new self($type, $date->startOfYear(), $date->endOfYear()->startOfDay()),
            self::CUSTOM => new self($type, ($from ?? $date)->startOfDay(), ($to ?? $date)->startOfDay()),
            default => new self(self::MONTH, $date->startOfMonth(), $date->endOfMonth()->startOfDay()),
        };
    }

    /**
     * Même période un an plus tôt (un 29 février devient le 28).
     */
    public function previousYear(): self
    {
        $from = $this->from->subYearNoOverflow();
        $to = match ($this->type) {
            self::MONTH => $from->endOfMonth()->startOfDay(),
            self::YEAR => $from->endOfYear()->startOfDay(),
            default => $this->to->subYearNoOverflow(),
        };

        return new self($this->type, $from, $to);
    }

    /**
     * Dernier jour à lire : la fin de la période, ou aujourd'hui si elle n'est pas finie.
     */
    public function until(CarbonImmutable $today): CarbonImmutable
    {
        $today = $today->startOfDay();

        return $this->to->greaterThan($today) ? $today : $this->to;
    }

    /**
     * @return array{type: string, from: string, to: string, previous: array{from: string, to: string}}
     */
    public function toArray(): array
    {
        $previous = $this->previousYear();

        return [
            'type' => $this->type,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'previous' => ['from' => $previous->from->toDateString(), 'to' => $previous->to->toDateString()],
        ];
    }
}
