<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Support\Facades\Gate;

/**
 * GET /cga/dashboard : tableau de bord du CGA (F-DSH-02), pour une période (F-DSH-04).
 */
class CgaDashboardRequest extends StatPeriodRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewCgaDashboard', StatOrgDaily::class);
    }
}
