<?php

namespace App\Domain\Closing\Http\Requests;

use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Domain\Closing\Models\ClosingCampaignItem;
use App\Domain\Closing\Support\ClosingProgress;
use App\Domain\Members\Enums\Portfolio;
use App\Support\Http\ListRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/cga/closing-campaigns/{closingCampaign}/items : dossiers de la campagne (tableau de
 * suivi, RG-OBL-11), filtrés par portefeuille, agent, étape en cours (« done » : dépôt fait) et retard.
 */
class ClosingItemsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', ClosingCampaign::class);
    }

    protected function filters(): array
    {
        return [
            'portfolio' => [Rule::enum(Portfolio::class)],
            'assigned_to' => ['string', 'ulid'],
            'step' => [Rule::in([...ClosingStep::values(), 'done'])],
            'late' => ['boolean'],
        ];
    }

    public function sorts(): array
    {
        return ['created_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(ClosingCampaign $campaign, CarbonImmutable $today): array
    {
        return [
            AllowedFilter::exact('portfolio'),
            AllowedFilter::exact('assigned_to'),
            AllowedFilter::callback('step', /** @param Builder<ClosingCampaignItem> $items */ static fn (Builder $items, mixed $step) => ClosingProgress::atStep($items, ClosingStep::tryFrom((string) $step))),
            AllowedFilter::callback('late', /** @param Builder<ClosingCampaignItem> $items */ static fn (Builder $items, mixed $late) => filter_var($late, FILTER_VALIDATE_BOOLEAN) ? ClosingProgress::late($campaign, $items, $today) : $items),
        ];
    }
}
