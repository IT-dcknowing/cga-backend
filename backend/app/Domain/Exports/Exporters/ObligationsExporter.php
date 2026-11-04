<?php

namespace App\Domain\Exports\Exporters;

use App\Domain\Exports\Contracts\Exporter;
use App\Domain\Exports\Data\ExportCriteria;
use App\Domain\Exports\Data\ExportSheet;
use App\Domain\Exports\Support\ExportFormat;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Models\ObligationInstance;
use App\Support\Tenancy\TenantContext;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Échéances des obligations des adhérents (F-EXP-01, planning du portefeuille /cga/echeances) : période sur la
 * date d'échéance, catégories et statuts choisis ; dossiers du portefeuille Cabinet seulement avec
 * members.cabinet.view.
 */
final class ObligationsExporter implements Exporter
{
    public function __construct(private readonly TenantContext $context) {}

    public function filterRules(): array
    {
        return [
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::enum(ObligationCategory::class)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(InstanceStatus::class)],
        ];
    }

    public function sheets(ExportCriteria $criteria): iterable
    {
        $headers = ['due_date', 'member', 'ncc', 'label', 'category', 'kind', 'period', 'amount_due', 'amount_paid', 'balance', 'status'];

        yield new ExportSheet(
            (string) __('exports.sheets.obligations'),
            array_map(static fn (string $key): string => (string) __("exports.columns.obligations.{$key}"), $headers),
            $this->rows($criteria),
        );
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(ExportCriteria $criteria): Generator
    {
        $query = ObligationInstance::query()
            ->with('member')
            ->when(! $this->context->allows('members.cabinet.view'), static fn (Builder $instances) => $instances->whereHas('member', static fn (Builder $member) => $member->where('portfolio', Portfolio::Cga)))
            ->when($criteria->list('categories') !== [], static fn (Builder $instances) => $instances->whereIn('category', $criteria->list('categories')))
            ->when($criteria->list('statuses') !== [], static fn (Builder $instances) => $instances->whereIn('status', $criteria->list('statuses')))
            ->when($criteria->from !== null, static fn (Builder $instances) => $instances->whereBetween('due_date', [$criteria->from?->toDateString(), $criteria->to?->toDateString()]))
            ->orderBy('due_date')
            ->orderBy('id');

        foreach ($query->lazy() as $instance) {
            yield [
                ExportFormat::date($instance->due_date),
                $instance->member->company_name,
                $instance->member->ncc,
                $instance->label,
                $instance->category->label(),
                $instance->kind->label(),
                $instance->period_label,
                $instance->amount_due,
                $instance->amount_paid,
                $instance->balance(),
                $instance->status->label(),
            ];
        }
    }
}
