<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/{member}/pieces/{pieceType} : dépôt d'une pièce (F-PJ-01), en multipart —
 * PDF, JPG ou PNG, files.max_upload_mb au plus ; date de validité pour une pièce datée.
 */
class StorePieceRequest extends FormRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('managePieces', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('file');

        return $file;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $date = $this->validated('expires_at');

        return is_string($date) ? CarbonImmutable::parse($date) : null;
    }
}
