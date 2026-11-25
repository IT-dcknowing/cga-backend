<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Support\Facades\Gate;

/**
 * GET /cga/statistics : position du CGA dans le réseau (F-STA-02), pour une période.
 */
class CgaPositionRequest extends StatPeriodRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewPosition', StatOrgDaily::class);
    }
}
