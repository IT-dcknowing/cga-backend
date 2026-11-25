<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Dues\Enums\DuesStatus;
use App\Domain\Dues\Models\NetworkDue;
use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Exports\Support\ExportFormat;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Validation\Rule;

/**
 * Cotisations des CGA au CNCGA (F-EXP-01, /cncga/cotisations) : une ligne par CGA et par année, des années de la
 * période (l'année en cours sans période) ; statuts choisis ; montant dû, payé, reste, date butoir et dernier
 * paiement confirmé.
 */
final class DuesExporter implements Exporter
{
    public function filterRules(): array
    {
        return [
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(DuesStatus::class)],
        ];
    }

    public function sheets(ExportCriteria $criteria): iterable
    {
        $headers = ['year', 'organization', 'agreement_number', 'amount_due', 'amount_paid', 'balance', 'deadline', 'status', 'last_payment'];

        yield new ExportSheet(
            (string) __('exports.sheets.dues'),
            array_map(static fn (string $key): string => (string) __("exports.columns.dues.{$key}"), $headers),
            $this->rows($criteria),
        );
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(ExportCriteria $criteria): Generator
    {
        $from = $criteria->from->year ?? CarbonImmutable::today()->year;
        $to = $criteria->to->year ?? $from;
        $dues = Tenancy::bypass(static fn () => NetworkDue::query()
            ->with(['organization', 'payments' => static fn ($payments) => $payments->where('status', PaymentStatus::Confirmed)])
            ->whereBetween('year', [$from, $to])
            ->when($criteria->list('statuses') !== [], static fn ($query) => $query->whereIn('status', $criteria->list('statuses')))
            ->orderBy('year')
            ->get()
            ->sortBy(static fn (NetworkDue $due): string => $due->year.mb_strtolower($due->organization->name))
            ->values(), 'Exports : cotisations du réseau lues par le CNCGA (F-COT-01).');

        foreach ($dues as $due) {
            yield [
                $due->year,
                $due->organization->name,
                $due->organization->agreement_number,
                $due->amount_due,
                $due->amount_paid,
                max(0, $due->amount_due - $due->amount_paid),
                ExportFormat::date($due->deadline),
                $due->status->label(),
                ExportFormat::date($due->payments->sortByDesc(static fn ($payment): string => $payment->paid_on->toDateString())->first()?->paid_on),
            ];
        }
    }
}
