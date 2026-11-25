<?php

namespace App\Domain\Imports\Http\Requests;

use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Imports\Models\ImportBatch;
use App\Support\Http\ListRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/network/imports/{importBatch}/rows : sort de chaque ligne du fichier (rapport
 * d'anomalies ligne par ligne), filtré par statut, dans l'ordre du fichier.
 */
class ListImportRowsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', $this->batch());
    }

    protected function filters(): array
    {
        return [
            'status' => [Rule::enum(ImportRowStatus::class)],
        ];
    }

    public function sorts(): array
    {
        return ['row_number'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [AllowedFilter::exact('status')];
    }

    /**
     * @throws AuthorizationException route sans lot
     */
    public function batch(): ImportBatch
    {
        $batch = $this->route('importBatch');

        return $batch instanceof ImportBatch ? $batch : throw new AuthorizationException;
    }
}
