<?php

namespace App\Domain\Documents\Http\Requests;

use App\Domain\Documents\Models\DocumentTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/platform/document-templates/{template}/activate : activation d'une version après sa
 * validation par le CNCGA (F-ADM-02, RG-DATA-03), dont la date est obligatoire et passée.
 */
class ActivateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('activate', $this->template());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'approved_by_cncga_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function approvedOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', (string) $this->validated('approved_by_cncga_on'))?->startOfDay() ?? CarbonImmutable::today();
    }

    /**
     * @throws AuthorizationException route sans modèle
     */
    public function template(): DocumentTemplate
    {
        $template = $this->route('template');

        return $template instanceof DocumentTemplate ? $template : throw new AuthorizationException;
    }
}
