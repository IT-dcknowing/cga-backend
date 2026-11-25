<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Http\Requests\Concerns\RouteMember;
use App\Support\Config\SettingsService;
use App\Support\Phones\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/cga/members/{member} : modification de la fiche (F-ADH-04), avec, sur un dossier
 * validé, le justificatif d'un changement de raison sociale ou de NCC (RG-ADH-06b). Envoyée en
 * multipart avec _method=PUT quand elle porte un fichier.
 */
class UpdateMemberRequest extends MemberRequest
{
    use RouteMember;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->member());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones, TenantContext $context, ?SettingsService $settings = null): array
    {
        $settings ??= app(SettingsService::class);

        return [
            ...parent::rules($phones, $context),
            'justification' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function justification(): ?UploadedFile
    {
        $file = $this->validated('justification');

        return $file instanceof UploadedFile ? $file : null;
    }

    protected function fixedPortfolio(): ?Portfolio
    {
        return $this->member()->portfolio;
    }
}
