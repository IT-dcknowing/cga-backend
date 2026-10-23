<?php

namespace App\Domain\Documents\Listeners;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\DocumentFormat;
use App\Domain\Members\Events\RadiationValidated;

/**
 * RG-DOC-08 : une radiation validée annule l'attestation d'adhésion en cours de validité, dans la
 * transaction de la validation, avec la date de la radiation pour motif et le valideur pour auteur.
 */
class CancelCertificatesOnRadiation
{
    public function handle(RadiationValidated $event): void
    {
        $radiation = $event->radiation;
        $certificates = GeneratedDocument::query()
            ->where('member_id', $radiation->member_id)
            ->where('type', DocumentType::MembershipCertificate)
            ->where('status', DocumentStatus::Valid)
            ->where(static fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', today()))
            ->lockForUpdate()
            ->get();

        foreach ($certificates as $certificate) {
            $certificate->status = DocumentStatus::Cancelled;
            $certificate->cancelled_at = now()->toImmutable();
            $certificate->cancelled_by = $radiation->validated_by;
            $certificate->cancel_reason = (string) __('documents.radiation_cancel_reason', ['date' => DocumentFormat::shortDate($radiation->validated_at)]);
            $certificate->save();
        }
    }
}
