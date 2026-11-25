<?php

namespace App\Domain\Reports\Http\Requests;

use App\Domain\Reports\Models\QuarterlyReport;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/quarterly-reports/{quarterlyReport}/acknowledge : accusé de réception du SP-CGA
 * (F-RPT-05) — date, référence, fichier scanné (PDF ou image, vérifié par son contenu ; multipart).
 */
class AcknowledgeReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('transmit', QuarterlyReport::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'acknowledged_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function acknowledgedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('acknowledged_on'));
    }

    public function reference(): string
    {
        return trim((string) $this->validated('reference'));
    }

    public function scan(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->validated('file');
    }
}
