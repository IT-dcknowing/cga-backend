<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\RegionalDirectorate;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Carbon\CarbonInterface;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CNCGA, CGA ou organisation technique « plateforme » (05-BACKEND-SCHEMA §3).
 *
 * @property string $id
 * @property OrganizationType $type
 * @property string $name
 * @property string|null $sigle
 * @property string|null $numbering_abbreviation
 * @property OrganizationStatus $status
 * @property AgreementStatus $agreement_status
 * @property SubscriptionStatus $subscription_status
 * @property DataStatus $data_status
 * @property string|null $legal_form
 * @property int|null $share_capital
 * @property string|null $ncc
 * @property string|null $rccm
 * @property string|null $agreement_number
 * @property CarbonInterface|null $agreement_date
 * @property CarbonInterface|null $agreement_withdrawn_at
 * @property string|null $regional_directorate_id
 * @property string|null $tax_office_id
 * @property string|null $commune_id
 * @property string|null $said
 * @property string|null $address
 * @property string $city
 * @property string $phone_1
 * @property string|null $phone_2
 * @property string|null $email
 * @property string|null $manager_name
 * @property string|null $expert_name
 * @property string|null $signatory_name
 * @property string|null $signatory_title
 * @property string|null $history_text
 * @property int|null $previous_year_revenue
 * @property string|null $logo_file_id
 * @property string|null $signature_file_id
 * @property string|null $stamp_file_id
 * @property CarbonInterface|null $abbreviation_locked_at
 * @property CarbonInterface|null $suspended_at
 * @property string|null $suspension_reason
 * @property CarbonInterface|null $subscription_started_at
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $subscription_ended_at
 * @property CarbonInterface|null $data_deletion_due_at
 * @property bool $banking_enabled
 * @property CarbonInterface|null $profile_completed_at
 * @property CarbonInterface|null $created_at
 * @property-read RegionalDirectorate|null $regionalDirectorate
 * @property-read TaxOffice|null $taxOffice
 * @property-read Commune|null $commune
 * @property-read File|null $logo
 * @property-read File|null $signature
 * @property-read File|null $stamp
 * @property-read Collection<int, OrganizationStatusEvent> $statusEvents
 * @property-read Membership|null $manager
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Auditable, HasFactory, HasUlids, SoftDeletes;

    /**
     * Fiche d'identité saisissable. Le type, les statuts, l'abréviation de numérotation, l'abonnement
     * et les dates calculées sont fixés par les Actions (02-TRD §6.7).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'sigle', 'legal_form', 'share_capital', 'ncc', 'rccm', 'agreement_number', 'agreement_date',
        'agreement_expires_at', 'regional_directorate_id', 'tax_office_id', 'said', 'address', 'city', 'commune_id',
        'phone_1', 'phone_2', 'email', 'manager_name', 'expert_name', 'signatory_name', 'signatory_title',
        'history_text', 'previous_year_revenue',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
            'agreement_status' => AgreementStatus::class,
            'subscription_status' => SubscriptionStatus::class,
            'data_status' => DataStatus::class,
            'share_capital' => 'integer',
            'previous_year_revenue' => 'integer',
            'banking_enabled' => 'boolean',
            'agreement_date' => 'immutable_date',
            'agreement_expires_at' => 'immutable_date',
            'agreement_withdrawn_at' => 'immutable_date',
            'subscription_started_at' => 'immutable_date',
            'trial_ends_at' => 'immutable_date',
            'subscription_ended_at' => 'immutable_date',
            'data_deletion_due_at' => 'immutable_date',
            'abbreviation_locked_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'profile_completed_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Champs exigés avant tout document officiel (F-ORG-03, RG-ORG-04) : identité fiscale et légale,
     * agrément, rattachement à la DR et au CDI, coordonnées, gérant, expert-comptable référent,
     * abréviation de numérotation, logo, signature et cachet.
     */
    public const PROFILE_REQUIRED_FIELDS = [
        'ncc', 'rccm', 'legal_form', 'share_capital', 'agreement_number', 'agreement_date', 'regional_directorate_id',
        'tax_office_id', 'address', 'city', 'phone_1', 'email', 'manager_name', 'expert_name', 'numbering_abbreviation',
        'logo_file_id', 'signature_file_id', 'stamp_file_id',
    ];

    /**
     * Champs exigés encore vides (RG-ORG-04 : la production de documents montre cette liste).
     *
     * @return list<string>
     */
    public function missingProfileFields(): array
    {
        return array_values(array_filter(
            self::PROFILE_REQUIRED_FIELDS,
            fn (string $field): bool => blank($this->getAttribute($field)),
        ));
    }

    /**
     * @return HasMany<OrganizationStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrganizationStatusEvent::class);
    }

    /**
     * @return BelongsTo<RegionalDirectorate, $this>
     */
    public function regionalDirectorate(): BelongsTo
    {
        return $this->belongsTo(RegionalDirectorate::class);
    }

    /**
     * @return BelongsTo<TaxOffice, $this>
     */
    public function taxOffice(): BelongsTo
    {
        return $this->belongsTo(TaxOffice::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Gérant du CGA, invité ou actif (« Ouvrir l'accès », F-ORG-02).
     *
     * @return HasOne<Membership, $this>
     */
    public function manager(): HasOne
    {
        return $this->hasOne(Membership::class)
            ->where('role', Role::CgaManager)
            ->whereIn('status', [MembershipStatus::Invited, MembershipStatus::Active]);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(File::class, 'logo_file_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(File::class, 'signature_file_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function stamp(): BelongsTo
    {
        return $this->belongsTo(File::class, 'stamp_file_id');
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
