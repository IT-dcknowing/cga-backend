<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Http\Requests\Concerns\ConcernAttachments;
use App\Domain\Concerns\Http\Requests\Concerns\RouteConcern;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /cga/concerns/{concern}/transfer : « Transmettre au CNCGA » (F-PRE-03) — message d'accompagnement
 * et pièces jointes choisies par le gérant ou l'expert-comptable, en multipart.
 */
class TransferConcernRequest extends FormRequest
{
    use ConcernAttachments, RouteConcern;

    public function authorize(): bool
    {
        return Gate::allows('transfer', $this->concern());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'message' => ['required', 'string', $this->messageRule()],
            ...$this->attachmentRules($settings),
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('message');
    }
}
