<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\Enums\CgaImageKind;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Logo, image de la signature du gérant ou image du cachet du CGA (F-ORG-03), utilisés dans ses
 * documents (RG-DOC-01, 02). Le fichier est rangé dans les fichiers du CGA ; la complétude de la
 * fiche est recalculée (RG-ORG-04).
 */
class StoreCgaImage
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly UpdateCgaProfile $profile,
    ) {}

    /**
     * @throws BusinessRuleException organization_terminated
     */
    public function execute(Organization $organization, CgaImageKind $kind, UploadedFile $image, User $uploader): Organization
    {
        return DB::transaction(function () use ($organization, $kind, $image, $uploader): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->status === OrganizationStatus::Terminated) {
                throw new BusinessRuleException('organization_terminated');
            }

            $file = $this->storage->storeUpload($image, 'branding', $organization->id, $uploader->id);
            $organization->setAttribute($kind->column(), $file->id);
            $this->profile->refreshCompletion($organization);
            $organization->save();

            return $organization;
        });
    }
}
