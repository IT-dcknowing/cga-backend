<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /network/statistics/brochures : plaquettes statistiques produites par le CNCGA (F-STA-03), sans paramètre.
 */
class ListBrochuresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('brochure', StatOrgDaily::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
