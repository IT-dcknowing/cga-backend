<?php

namespace App\Domain\Trainings\Composers;

use App\Domain\Documents\Composers\CgaIdentity;
use App\Domain\Documents\Composers\DocumentComposer;
use App\Domain\Documents\Data\DocumentContent;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;

/**
 * Attestation de participation d'un présent (F-FOR-04, modèle training_certificate) : organisateur (CNCGA ou
 * CGA, identité de sa fiche), personne, structure, formation (dates, format, lieu, formateur, durée). Pour une
 * formation du CNCGA, un adhérent n'est désigné que comme adhérent de son CGA, jamais par son entreprise
 * (RG-SEC-01, décision 0013). L'inscription appartient au CGA de la personne : lue par un contournement nommé.
 */
class TrainingCertificateComposer implements DocumentComposer
{
    public function __construct(private readonly CgaIdentity $identity) {}

    public function compose(GeneratedDocument $document): DocumentContent
    {
        $issuer = Organization::query()->with(['logo', 'signature', 'stamp'])->findOrFail($document->organization_id);
        $registration = TrainingAccess::acrossCentres(static fn (): TrainingRegistration => TrainingRegistration::query()
            ->with(['training', 'user', 'member', 'membership.organization'])
            ->findOrFail($document->source_id));
        $training = $registration->training;

        return new DocumentContent([
            ...$this->identity->values($issuer),
            ...TrainingFacts::values($training),
            'attendee_name' => $registration->user->fullName(),
            'attendee_organization' => match (true) {
                $registration->member === null => $registration->membership->organization->name,
                $issuer->type === OrganizationType::Cncga => (string) __('trainings.member_of', ['cga' => $registration->membership->organization->name]),
                default => $registration->member->company_name,
            },
            'document_date' => DocumentFormat::date($document->issued_at),
        ], $this->identity->images($issuer));
    }
}
