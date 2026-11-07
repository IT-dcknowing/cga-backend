<?php

namespace App\Domain\Platform\Http\Requests;

use App\Domain\Platform\Models\FailedJob;
use App\Support\Http\ListRequest;
use App\Support\Queue\QueueMonitor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/platform/failed-jobs : travaux en échec, les plus récents d'abord, filtrés par file (liste blanche des
 * files surveillées).
 */
class ListFailedJobsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', FailedJob::class);
    }

    protected function filters(): array
    {
        return ['queue' => ['string', Rule::in(app(QueueMonitor::class)->queues())]];
    }

    public function sorts(): array
    {
        return ['failed_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [AllowedFilter::exact('queue')];
    }
}
