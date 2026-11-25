<?php

namespace App\Domain\Documents\Composers;

use App\Domain\Documents\Models\File;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Models\Organization;
use App\Support\Files\StorageService;
use App\Support\Phones\PhoneNormalizer;

/**
 * Identité du CGA émetteur, injectée dans chaque modèle (02-TRD §8.1, RG-DOC-01, 02) : dénomination,
 * NCC, RCCM, agrément, forme et capital, siège, contacts, signataire (le gérant à défaut), lieu des
 * documents, et les images du logo, de la signature et du cachet.
 */
class CgaIdentity
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * @return array<string, string>
     */
    public function values(Organization $organization): array
    {
        $signatoryName = DocumentFormat::text($organization->signatory_name ?? $organization->manager_name);
        $signatoryTitle = DocumentFormat::text($organization->signatory_title ?? (string) __('documents.default_signatory_title'));
        $phones = array_map($this->phones->display(...), array_values(array_filter([$organization->phone_1, $organization->phone_2])));

        return [
            'cga_name' => $organization->name,
            'cga_ncc' => DocumentFormat::text($organization->ncc),
            'cga_rccm' => DocumentFormat::text($organization->rccm),
            'cga_agreement' => (string) __('documents.agreement', [
                'number' => DocumentFormat::text($organization->agreement_number),
                'date' => DocumentFormat::date($organization->agreement_date),
            ]),
            'cga_legal_form' => DocumentFormat::text($organization->legal_form),
            'cga_legal_form_full' => DocumentFormat::legalForm($organization->legal_form),
            'cga_share_capital' => DocumentFormat::amount($organization->share_capital),
            'cga_address' => DocumentFormat::text(implode(', ', array_filter([$organization->address, $organization->city]))),
            'cga_phones' => DocumentFormat::text(implode(' · ', $phones)),
            'cga_email' => DocumentFormat::text($organization->email),
            'cga_signatory' => (string) __('documents.signatory', ['name' => $signatoryName, 'title' => $signatoryTitle]),
            'cga_signatory_name' => $signatoryName,
            'cga_signatory_title' => $signatoryTitle,
            'document_place' => $organization->city,
        ];
    }

    /**
     * Images de la fiche du CGA (RG-ORG-04 : présentes dès que la fiche est complète).
     *
     * @return array<string, string> variable => contenu de l'image
     */
    public function images(Organization $organization): array
    {
        $images = ['cga_logo' => $organization->logo, 'cga_signature' => $organization->signature, 'cga_stamp' => $organization->stamp];

        return array_map(fn (File $file): string => $this->storage->contents($file), array_filter($images));
    }
}
