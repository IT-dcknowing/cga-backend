<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Data\RadiationDemand;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/radiation : « Demander la radiation » (F-ADH-06), en multipart
 * quand elle porte la pièce. Pour un contribuable ou un décès, validate_now valide dans la même
 * opération, avec l'issue (RG-ADH-17). La cohérence initiateur / motif / pièce est contrôlée par
 * l'Action (config/radiations.php).
 */
class RequestRadiationRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('requestRadiation', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'initiator' => ['required', Rule::enum(RadiationInitiator::class)],
            'reason' => ['required', Rule::enum(RadiationReason::class)],
            'reason_other' => ['nullable', 'required_if:reason,other', 'prohibited_unless:reason,other', 'string', 'max:200'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'observation' => ['required', 'string', 'max:5000'],
            'proof' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
            'sp_request_reference' => ['nullable', 'string', 'max:100'],
            'validate_now' => ['nullable', 'boolean'],
            'outcome' => ['nullable', 'required_if_accepted:validate_now', Rule::enum(RadiationOutcome::class)],
            'validation_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function demand(): RadiationDemand
    {
        return RadiationDemand::fromValidated($this->validated());
    }

    public function proof(): ?UploadedFile
    {
        $file = $this->validated('proof');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * Validation demandée dans la même opération : issue et référence.
     *
     * @return array{outcome: RadiationOutcome, reference: string|null}|null
     */
    public function immediate(): ?array
    {
        if (! (bool) $this->validated('validate_now')) {
            return null;
        }
        $reference = $this->validated('validation_reference');

        return ['outcome' => RadiationOutcome::from((string) $this->validated('outcome')), 'reference' => is_string($reference) ? $reference : null];
    }
}
