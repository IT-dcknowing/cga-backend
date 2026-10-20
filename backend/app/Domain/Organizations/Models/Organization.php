<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Enums\AgreementStatus;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Enums\SubscriptionStatus;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Users\Models\Membership;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $phone_1
 * @property string|null $email
 * @property bool $banking_enabled
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
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
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
