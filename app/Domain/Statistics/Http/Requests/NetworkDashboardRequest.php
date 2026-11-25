<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Support\Facades\Gate;

/**
 * GET /network/dashboard : tableau de bord du CNCGA (F-DSH-01), pour une période (F-DSH-04).
 */
class NetworkDashboardRequest extends StatPeriodRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewNetworkDashboard', StatOrgDaily::class);
    }
}
