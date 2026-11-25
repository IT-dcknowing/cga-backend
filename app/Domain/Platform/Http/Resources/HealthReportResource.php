<?php

namespace App\Domain\Platform\Http\Resources;

use App\Support\Health\HealthCheckResult;
use App\Support\Health\HealthReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * État de chaque contrôle, avec un code d'anomalie et sans aucun détail technique.
 *
 * @property HealthReport $resource
 */
class HealthReportResource extends JsonResource
{
    /**
     * @return array{status: string, checks: array<string, array{status: string, error?: string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'ok'|'failed' */
            'status' => $this->resource->status()->value,
            /** @var array<string, array{status: 'ok'|'failed', error?: 'unavailable'|'not_writable'|'low_space'}> */
            'checks' => array_map($this->check(...), $this->resource->results),
        ];
    }

    /**
     * @return array{status: string, error?: string}
     */
    private function check(HealthCheckResult $result): array
    {
        $check = ['status' => $result->status()->value];

        if ($result->error !== null) {
            $check['error'] = $result->error->value;
        }

        return $check;
    }
}
