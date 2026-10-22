<?php

use App\Domain\Auth\Enums\InvitationChannel;
use App\Domain\Auth\Enums\OtpPurpose;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Imports\Enums\ImportStatus;
use App\Domain\Imports\Enums\ImportType;
use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeeKind;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\MovementDirection;
use App\Domain\Members\Enums\PiecesStatus;
use App\Domain\Members\Enums\PieceStatus;
use App\Domain\Members\Enums\PieceType;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Enums\RadiationStatus;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\RevenueSource;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Notifications\Enums\PreferenceChannel;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\CgaImageKind;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationStatusEvent;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\ReferenceData\ImportMode;
use App\Domain\Referentials\ReferenceData\ReferenceDataset;
use App\Domain\Referentials\ReferenceData\Removal;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Enums\UserStatus;

/*
 * Chaque valeur des listes a son libellé français dans lang/fr/enums.php (02-TRD §7.6).
 */

it('donne un libellé français à chaque valeur', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case->label())->not->toStartWith('enums.');
    }
})->with([
    Role::class, MembershipStatus::class, UserStatus::class, InvitationChannel::class, OtpPurpose::class,
    OrganizationType::class, OrganizationStatus::class, AgreementStatus::class, SubscriptionStatus::class, DataStatus::class,
    PreferenceChannel::class, SectorStatus::class, OrganizationStatusEvent::class, CgaImageKind::class,
    ImportType::class, ImportStatus::class, ImportRowStatus::class, ReferenceDataset::class, ImportMode::class, Removal::class,
    Portfolio::class, MemberStatus::class, EntityType::class, LegalForm::class, TaxRegime::class, FeesPeriodicity::class, PiecesStatus::class, PieceType::class, PieceStatus::class, RadiationInitiator::class, RadiationReason::class, RadiationStatus::class, RadiationOutcome::class, RevenueKind::class, RevenueSource::class, MovementDirection::class, FeeKind::class,
    ChargeKind::class, ChargeStatus::class, PaymentMethod::class, PaymentSource::class, PaymentStatus::class, DocumentType::class, DocumentStatus::class,
]);

it('nomme les rôles comme le PRD', function () {
    expect(Role::CgaManager->label())->toBe('Gérant')
        ->and(Role::PlatformAdmin->label())->toBe('Administrateur de la plateforme')
        ->and(Role::values())->toHaveCount(16);
});
