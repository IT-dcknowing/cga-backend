<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Exports\Support\ExportFormat;
use App\Support\Tenancy\Tenancy;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Préoccupations des CGA au CNCGA (F-EXP-01, /cncga/preoccupations) : CGA émetteur, catégorie, objet, statut et
 * délais ; statuts et catégories choisis ; période sur la date de dépôt. Aucune donnée d'adhérent (RG-PRE-03).
 */
final class NetworkConcernsExporter implements Exporter
{
    public function filterRules(): array
    {
        return [
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(ConcernStatus::class)],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::enum(ConcernCategory::class)],
        ];
    }

    public function sheets(ExportCriteria $criteria): iterable
    {
        $headers = ['submitted_at', 'organization', 'category', 'subject', 'status', 'target_days', 'first_response_at', 'resolved_at'];

        yield new ExportSheet(
            (string) __('exports.sheets.network_concerns'),
            array_map(static fn (string $key): string => (string) __("exports.columns.network_concerns.{$key}"), $headers),
            $this->rows($criteria),
        );
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(ExportCriteria $criteria): Generator
    {
        $concerns = Tenancy::bypass(static fn () => Concern::query()
            ->with('organization')
            ->where('level', ConcernLevel::CgaToCncga)
            ->when($criteria->list('statuses') !== [], static fn (Builder $query) => $query->whereIn('status', $criteria->list('statuses')))
            ->when($criteria->list('categories') !== [], static fn (Builder $query) => $query->whereIn('category', $criteria->list('categories')))
            ->when($criteria->from !== null, static fn (Builder $query) => $query->whereBetween('created_at', [$criteria->from?->startOfDay(), $criteria->to?->endOfDay()]))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(), 'Exports : demandes des CGA lues par le CNCGA (F-PRE-02).');

        foreach ($concerns as $concern) {
            yield [
                ExportFormat::dateTime($concern->created_at),
                $concern->organization->name,
                $concern->category->label(),
                $concern->subject,
                $concern->status->label(),
                $concern->target_response_days,
                ExportFormat::dateTime($concern->first_response_at),
                ExportFormat::dateTime($concern->resolved_at),
            ];
        }
    }
}
