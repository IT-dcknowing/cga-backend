<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members/{member}/regimes : « Changer de régime » (F-ADH-05) — nouveau régime, date
 * d'effet, motif et justificatif (RG-ADH-06b), en multipart.
 */
class ChangeRegimeRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('changeRegime', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'regime' => ['required', Rule::enum(TaxRegime::class)],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:1000'],
            'justification' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function regime(): TaxRegime
    {
        return TaxRegime::from((string) $this->validated('regime'));
    }

    public function effectiveDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('effective_date'));
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function justification(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('justification');

        return $file;
    }
}
