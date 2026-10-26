<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PiecesStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Payments\Enums\ChargeKind;
use App\Domain\Payments\Enums\ChargeStatus;
use App\Domain\Payments\Models\MemberCharge;
use App\Domain\Payments\Models\MemberPayment;
use App\Domain\Referentials\Models\ActivityCode;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dossier d'un adhérent du centre (05-BACKEND-SCHEMA §5), dans le portefeuille CGA ou Cabinet
 * (RG-PTF-01, 02). Le portefeuille, le statut, les numéros, le contrat, les dates de validation et
 * l'état des pièces sont fixés par les Actions, jamais par la saisie (02-TRD §6.7).
 *
 * @property string $id
 * @property string $organization_id
 * @property Portfolio $portfolio
 * @property MemberStatus $status
 * @property string|null $membership_number
 * @property int|null $membership_seq
 * @property string|null $client_number
 * @property string|null $legacy_number
 * @property string|null $referrer_id
 * @property CarbonInterface|null $cga_contract_signed_on
 * @property string|null $cga_contract_confirmed_by
 * @property CarbonInterface|null $cga_contract_ended_on
 * @property string|null $cga_contract_file_id
 * @property bool $attestation_blocked
 * @property CarbonInterface|null $adhesion_date
 * @property CarbonInterface|null $validated_at
 * @property string|null $validated_by
 * @property EntityType $entity_type
 * @property string $company_name
 * @property string|null $sigle
 * @property LegalForm $legal_form
 * @property int|null $share_capital
 * @property string|null $owner_last_name
 * @property string|null $owner_first_names
 * @property string|null $owner_nationality
 * @property string|null $owner_id_document_type
 * @property string|null $owner_id_document_number
 * @property string $ncc
 * @property string|null $rccm
 * @property string|null $idu
 * @property TaxRegime $regime
 * @property CarbonInterface $regime_since
 * @property string|null $tax_office_id
 * @property string|null $activity_code_id
 * @property string|null $activity_code_text
 * @property string|null $sector_id
 * @property string $main_activity
 * @property string|null $address
 * @property string|null $commune_id
 * @property string|null $land_section
 * @property string|null $land_parcel
 * @property CarbonInterface|null $registration_date
 * @property string $phone
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $signatory_name
 * @property string|null $signatory_title
 * @property int|null $employees_count
 * @property int $entry_fee_amount
 * @property int $fees_amount
 * @property FeesPeriodicity $fees_periodicity
 * @property PiecesStatus $pieces_status
 * @property CarbonInterface|null $rsi_since
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Collection<int, MemberPiece> $pieces
 * @property-read Collection<int, Radiation> $radiations
 * @property-read Radiation|null $currentRadiation
 * @property-read Collection<int, MemberRevenue> $revenues
 * @property-read BusinessReferrer|null $referrer
 * @property-read TaxOffice|null $taxOffice
 * @property-read Sector|null $sector
 * @property-read Commune|null $commune
 * @property-read ActivityCode|null $activityCode
 * @property-read Collection<int, MemberCharge> $overdueFees
 * @property-read AttestationBlock|null $currentAttestationBlock
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids, SoftDeletes;

    /**
     * Fiche saisissable du dossier (F-ADH-02). Le régime passe par ChangeRegime après la validation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_type', 'company_name', 'sigle', 'legal_form', 'share_capital', 'owner_last_name', 'owner_first_names',
        'owner_nationality', 'owner_id_document_type', 'owner_id_document_number', 'ncc', 'rccm', 'idu', 'regime',
        'regime_since', 'tax_office_id', 'activity_code_id', 'activity_code_text', 'sector_id', 'main_activity',
        'address', 'commune_id', 'land_section', 'land_parcel', 'registration_date', 'phone', 'whatsapp', 'email',
        'signatory_name', 'signatory_title', 'employees_count', 'entry_fee_amount', 'fees_amount', 'fees_periodicity',
        'legacy_number',
    ];

    /**
     * La pièce d'identité du dirigeant ne sort jamais du serveur en clair hors de la fiche.
     *
     * @var list<string>
     */
    protected $hidden = ['owner_id_document_number', 'search_text'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio' => Portfolio::class,
            'status' => MemberStatus::class,
            'entity_type' => EntityType::class,
            'legal_form' => LegalForm::class,
            'regime' => TaxRegime::class,
            'fees_periodicity' => FeesPeriodicity::class,
            'pieces_status' => PiecesStatus::class,
            'owner_id_document_number' => 'encrypted',
            'attestation_blocked' => 'boolean',
            'share_capital' => 'integer',
            'employees_count' => 'integer',
            'entry_fee_amount' => 'integer',
            'fees_amount' => 'integer',
            'membership_seq' => 'integer',
            'cga_contract_signed_on' => 'immutable_date',
            'cga_contract_ended_on' => 'immutable_date',
            'adhesion_date' => 'immutable_date',
            'regime_since' => 'immutable_date',
            'registration_date' => 'immutable_date',
            'rsi_since' => 'immutable_date',
            'validated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Dossiers du portefeuille CGA, seuls repris dans le rapport trimestriel, les états réglementaires,
     * les documents d'adhésion et les statistiques du CNCGA (RG-PTF-02, T-17).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function regulatory(Builder $query): void
    {
        $query->where('portfolio', Portfolio::Cga);
    }

    /**
     * Dossier ouvert : brouillon, actif ou radiation en cours.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [MemberStatus::Draft, MemberStatus::Active, MemberStatus::RadiationPending]);
    }

    /**
     * @return HasMany<MemberPiece, $this>
     */
    public function pieces(): HasMany
    {
        return $this->hasMany(MemberPiece::class);
    }

    /**
     * @return HasMany<Radiation, $this>
     */
    public function radiations(): HasMany
    {
        return $this->hasMany(Radiation::class);
    }

    /**
     * Radiation en vigueur : demandée ou validée, non annulée (RG-ADH-17).
     *
     * @return HasOne<Radiation, $this>
     */
    public function currentRadiation(): HasOne
    {
        return $this->hasOne(Radiation::class)->whereNull('cancelled_at')->latest('requested_at');
    }

    /**
     * @return HasMany<MemberRevenue, $this>
     */
    public function revenues(): HasMany
    {
        return $this->hasMany(MemberRevenue::class);
    }

    /**
     * @return HasMany<MemberRegimeChange, $this>
     */
    public function regimeHistory(): HasMany
    {
        return $this->hasMany(MemberRegimeChange::class);
    }

    /**
     * @return HasMany<PortfolioMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PortfolioMovement::class);
    }

    /**
     * Accès en ligne de l'adhérent (F-ADH-09).
     *
     * @return HasMany<Membership, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsTo<BusinessReferrer, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(BusinessReferrer::class, 'referrer_id');
    }

    /**
     * @return BelongsTo<TaxOffice, $this>
     */
    public function taxOffice(): BelongsTo
    {
        return $this->belongsTo(TaxOffice::class);
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return BelongsTo<ActivityCode, $this>
     */
    public function activityCode(): BelongsTo
    {
        return $this->belongsTo(ActivityCode::class);
    }

    /**
     * Sommes dues au CGA : droit d'adhésion, honoraires, formations (F-PAY-02).
     *
     * @return HasMany<MemberCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(MemberCharge::class);
    }

    /**
     * Échéances d'honoraires impayées (RG-PAY-03, 04), de la plus ancienne à la plus récente.
     *
     * @return HasMany<MemberCharge, $this>
     */
    public function overdueFees(): HasMany
    {
        return $this->hasMany(MemberCharge::class)->where('kind', ChargeKind::Fees)->where('status', ChargeStatus::Overdue)->orderBy('due_date');
    }

    /**
     * Blocage manuel de l'attestation en cours (RG-DOC-10).
     *
     * @return HasOne<AttestationBlock, $this>
     */
    public function currentAttestationBlock(): HasOne
    {
        return $this->hasOne(AttestationBlock::class)->whereNull('unblocked_at');
    }

    /**
     * @return HasMany<MemberPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(MemberPayment::class);
    }

    /**
     * Documents produits ou déposés pour ce dossier (F-DOC-01, 05).
     *
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }
}
