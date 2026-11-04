<?php

namespace App\Domain\Exports\Data;

use App\Domain\Exports\Models\Export;
use Carbon\CarbonImmutable;

/**
 * Période (bornes comprises, facultative) et filtres validés d'un export.
 */
final readonly class ExportCriteria
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public array $filters = [],
    ) {}

    public static function of(Export $export): self
    {
        return new self(
            $export->period_from === null ? null : CarbonImmutable::parse($export->period_from->toDateString()),
            $export->period_to === null ? null : CarbonImmutable::parse($export->period_to->toDateString()),
            $export->filters,
        );
    }

    /**
     * @return list<string>
     */
    public function list(string $filter): array
    {
        return array_values(array_map('strval', (array) ($this->filters[$filter] ?? [])));
    }

    public function value(string $filter): ?string
    {
        $value = $this->filters[$filter] ?? null;

        return $value === null ? null : (string) $value;
    }
}
