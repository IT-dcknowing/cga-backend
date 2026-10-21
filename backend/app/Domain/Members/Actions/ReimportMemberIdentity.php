<?php

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberTransfer;
use App\Domain\Members\Support\Ncc;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\Tenancy;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * « Réimporter depuis un autre CGA » (F-ADH-10, RG-ADH-15, 16) : quand les deux CGA sont abonnés et
 * que l'adhérent a donné son accord écrit (pièce jointe), le nouveau CGA reprend les données
 * d'identification du dossier tenu par l'autre CGA — identité, NCC, coordonnées — pour pré-remplir
 * son inscription ; jamais son historique de gestion (régime, chiffres d'affaires, pièces, honoraires,
 * numéros), ni le nom de l'autre CGA (RG-SEC-02). La lecture dans l'autre CGA est un contournement
 * journalisé ; le réimport est tracé (member_transfers) et relié au dossier créé ensuite. Le CGA qui
 * réimporte est abonné ou à l'essai : sans abonnement, il n'a aucun accès (RG-ORG-06).
 */
class ReimportMemberIdentity
{
    /**
     * Données d'identification reprises.
     */
    public const IDENTITY = [
        'entity_type', 'company_name', 'sigle', 'legal_form', 'share_capital', 'owner_last_name', 'owner_first_names',
        'owner_nationality', 'owner_id_document_type', 'owner_id_document_number', 'ncc', 'rccm', 'idu', 'registration_date',
        'phone', 'whatsapp', 'email', 'address', 'commune_id', 'land_section', 'land_parcel',
    ];

    public function __construct(private readonly StorageService $storage) {}

    /**
     * @return array{transfer: MemberTransfer, identity: array<string, mixed>}
     *
     * @throws BusinessRuleException ncc_already_registered, transfer_source_not_found
     */
    public function execute(string $organizationId, string $ncc, UploadedFile $consent, User $actor): array
    {
        $ncc = Ncc::normalize($ncc);

        return DB::transaction(function () use ($organizationId, $ncc, $consent, $actor): array {
            AssertNccAvailable::here($ncc);

            $source = $this->source($ncc, $organizationId) ?? throw new BusinessRuleException('transfer_source_not_found', status: 404);
            $file = $this->storage->storeUpload($consent, 'transfers', $organizationId, $actor->id);

            $transfer = new MemberTransfer;
            $transfer->from_member_id = $source->id;
            $transfer->to_organization_id = $organizationId;
            $transfer->consent_file_id = $file->id;
            $transfer->requested_by = $actor->id;
            $transfer->save();

            $identity = [];
            foreach (self::IDENTITY as $field) {
                $value = $source->getAttribute($field);
                $identity[$field] = $value instanceof BackedEnum ? $value->value : ($value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value);
            }

            return ['transfer' => $transfer->refresh(), 'identity' => $identity];
        });
    }

    /**
     * Dernier dossier validé de ce NCC dans un autre CGA abonné.
     */
    private function source(string $ncc, string $organizationId): ?Member
    {
        return Tenancy::bypass(static fn (): ?Member => Member::query()
            ->where('ncc', $ncc)
            ->where('organization_id', '!=', $organizationId)
            ->where('status', '!=', MemberStatus::Draft)
            ->whereHas('organization', static fn (Builder $organization) => $organization
                ->where('type', OrganizationType::Cga)
                ->whereIn('subscription_status', [SubscriptionStatus::Trial, SubscriptionStatus::Active]))
            ->orderByDesc('validated_at')
            ->first(), 'Réimport d\'identité depuis un autre CGA (RG-ADH-15), avec l\'accord écrit de l\'adhérent.');
    }
}
