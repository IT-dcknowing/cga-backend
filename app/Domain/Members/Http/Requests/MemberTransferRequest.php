<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Rules\ValidNcc;
use App\Support\Config\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/members/transfers : « Réimporter depuis un autre CGA » (RG-ADH-15) — NCC et accord
 * écrit de l'adhérent (PDF, JPG ou PNG), en multipart.
 */
class MemberTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('reimport', Member::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'ncc' => ['required', 'string', 'max:20', new ValidNcc],
            'consent' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function ncc(): string
    {
        return (string) $this->validated('ncc');
    }

    public function consent(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('consent');

        return $file;
    }
}
