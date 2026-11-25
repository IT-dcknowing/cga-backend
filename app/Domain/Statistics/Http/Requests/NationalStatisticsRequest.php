<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Support\Facades\Gate;

/**
 * GET /network/statistics, /network/statistics/map et le détail par commune d'une Direction régionale : statistiques nationales (F-STA-01), pour une période.
 */
class NationalStatisticsRequest extends StatPeriodRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewNational', StatOrgDaily::class);
    }
}
