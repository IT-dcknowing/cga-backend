<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Support\ConcernAccess;
use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Exports\Support\ExportFormat;
use App\Domain\Members\Enums\Portfolio;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Préoccupations reçues des adhérents du centre (F-EXP-01, onglet « Reçues » de /cga/preoccupations) : celles du
 * portefeuille Cabinet seulement avec members.cabinet.view, comme la liste ; statuts et catégories choisis ;
 * période sur la date de dépôt. Ni message ni note interne : la liste seulement.
 */
final class ConcernsExporter implements Exporter
{
    public function __construct(private readonly ConcernAccess $access) {}

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
        $headers = ['submitted_at', 'member', 'category', 'subject', 'status', 'target_days', 'first_response_at', 'resolved_at', 'reopen_count'];

        yield new ExportSheet(
            (string) __('exports.sheets.concerns'),
            array_map(static fn (string $key): string => (string) __("exports.columns.concerns.{$key}"), $headers),
            $this->rows($criteria),
        );
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(ExportCriteria $criteria): Generator
    {
        $query = Concern::query()
            ->with('member')
            ->where('level', ConcernLevel::MemberToCga)
            ->when(! $this->access->seesCabinet(), static fn (Builder $concerns) => $concerns->whereHas('member', static fn (Builder $member) => $member->where('portfolio', Portfolio::Cga)))
            ->when($criteria->list('statuses') !== [], static fn (Builder $concerns) => $concerns->whereIn('status', $criteria->list('statuses')))
            ->when($criteria->list('categories') !== [], static fn (Builder $concerns) => $concerns->whereIn('category', $criteria->list('categories')))
            ->when($criteria->from !== null, static fn (Builder $concerns) => $concerns->whereBetween('created_at', [$criteria->from?->startOfDay(), $criteria->to?->endOfDay()]))
            ->orderBy('created_at')
            ->orderBy('id');

        foreach ($query->lazy() as $concern) {
            yield [
                ExportFormat::dateTime($concern->created_at),
                $concern->member?->company_name,
                $concern->category->label(),
                $concern->subject,
                $concern->status->label(),
                $concern->target_response_days,
                ExportFormat::dateTime($concern->first_response_at),
                ExportFormat::dateTime($concern->resolved_at),
                $concern->reopen_count,
            ];
        }
    }
}
