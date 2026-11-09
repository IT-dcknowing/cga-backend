<?php

namespace App\Domain\Closing\Http\Requests;

use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/closing-campaigns/{closingCampaign}/items/{item}/steps/{step} : « Marquer l'étape
 * faite » (F-OBL-08) — date ; pour le dépôt légal, référence et accusé (03-APPFLOW §4, multipart).
 */
class MarkClosingStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', ClosingCampaign::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        $rules = ['done_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']];
        if ($this->step() === ClosingStep::Filing) {
            $rules['reference'] = ['required', 'string', 'max:100'];
            $rules['receipt'] = ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)];
        }

        return $rules;
    }

    public function step(): ?ClosingStep
    {
        $step = $this->route('step');

        return $step instanceof ClosingStep ? $step : ClosingStep::tryFrom((string) $step);
    }

    public function doneOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('done_on'));
    }

    public function reference(): ?string
    {
        $reference = $this->validated('reference');

        return is_string($reference) ? $reference : null;
    }

    public function receipt(): ?UploadedFile
    {
        $file = $this->validated('receipt');

        return $file instanceof UploadedFile ? $file : null;
    }
}
