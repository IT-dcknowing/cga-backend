<?php

namespace App\Domain\Organizations\Http\Requests;

use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use UnexpectedValueException;

/**
 * POST /api/v1/cga/profile/images/{kind} : logo, signature du gérant ou cachet du CGA (F-ORG-03).
 * Image PNG ou JPEG (type lu dans le contenu), au plus files.max_upload_mb.
 */
class CgaImageRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $context->has() && Gate::allows('updateProfile', $context->organization());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'image' => ['required', 'file', 'mimetypes:image/png,image/jpeg', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function uploadedImage(): UploadedFile
    {
        $image = $this->validated('image');

        return $image instanceof UploadedFile ? $image : throw new UnexpectedValueException('Image non validée.');
    }
}
