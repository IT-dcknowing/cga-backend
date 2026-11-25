<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Http\Requests\Concerns\RouteConcern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST …/concerns/{concern}/reopen : « Rouvrir la demande » par le demandeur — l'adhérent, ou le CGA pour
 * une demande au CNCGA (F-PRE-02).
 */
class ReopenConcernRequest extends FormRequest
{
    use RouteConcern;

    public function authorize(): bool
    {
        return Gate::allows('reopen', $this->concern());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
