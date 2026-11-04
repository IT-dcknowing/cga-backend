<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Http\Requests\Concerns\RouteConcern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET …/concerns/{concern} (CGA, adhérent) et /network/concerns/{concernId} (CNCGA) : une préoccupation
 * et son fil (F-PRE-02).
 */
class ShowConcernRequest extends FormRequest
{
    use RouteConcern;

    public function authorize(): bool
    {
        return Gate::allows('view', $this->concern());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
