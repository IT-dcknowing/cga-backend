<?php

namespace App\Domain\Closing\Support;

use App\Domain\Closing\Enums\ClosingStep;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;

/**
 * Calendrier de la campagne de clôture de l'exercice N (F-OBL-08, RG-OBL-10) : dates internes du
 * réseau, ajustables par chaque CGA (closing.calendar), et date de dépôt légal réglée par le CNCGA
 * seul (closing.filing_date) ; toutes en N+1.
 */
final class ClosingCalendar
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{code: string, due: string}>
     */
    public function steps(string $organizationId, int $fiscalYear): array
    {
        /** @var array<string, string> $internal */
        $internal = (array) $this->settings->get('closing.calendar', $organizationId);
        $dates = [...$internal, ClosingStep::Filing->value => (string) $this->settings->get('closing.filing_date')];

        return array_map(static fn (ClosingStep $step): array => [
            'code' => $step->value,
            'due' => CarbonImmutable::createFromFormat('!Y-m-d', ($fiscalYear + 1).'-'.$dates[$step->value])->toDateString(),
        ], ClosingStep::cases());
    }
}
