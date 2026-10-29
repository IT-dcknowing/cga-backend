<?php

namespace App\Domain\Obligations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\InstanceKind;
use App\Domain\Obligations\Enums\InstanceStatus;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\TaxNature;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\ObligationInstanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Échéance d'une obligation (F-OBL-03 à 05) : une déclaration, un paiement ou une diligence pour une
 * période, échéance reportée au jour ouvré (RG-OBL-06). Libellé, catégorie et nature d'impôt sont
 * copiés à la génération : une nouvelle version du catalogue ne change pas les échéances passées
 * (RG-DATA-04). Une ligne « autre administration » saisie à la main n'a pas d'obligation attribuée.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property string|null $member_obligation_id
 * @property string|null $obligation_type_id
 * @property string $label
 * @property ObligationCategory $category
 * @property InstanceKind $kind
 * @property string|null $authority_name
 * @property string $period_label
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property CarbonInterface $due_date
 * @property string|null $platform
 * @property TaxNature|null $tax_nature
 * @property int|null $amount_gross
 * @property int|null $amount_due
 * @property int $amount_paid
 * @property int|null $months_covered
 * @property InstanceStatus $status
 * @property InstanceStatus|null $member_declared_status
 * @property CarbonInterface|null $member_declared_at
 * @property string|null $parent_instance_id
 * @property CarbonInterface|null $done_at
 * @property-read Member $member
 * @property-read MemberObligation|null $memberObligation
 */
class ObligationInstance extends Model
{
    /** @use HasFactory<ObligationInstanceFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par le générateur et les Actions des obligations seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ObligationCategory::class,
            'kind' => InstanceKind::class,
            'tax_nature' => TaxNature::class,
            'status' => InstanceStatus::class,
            'member_declared_status' => InstanceStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_date' => 'immutable_date',
            'done_at' => 'immutable_date',
            'member_declared_at' => 'immutable_datetime',
            'reminder_j7_sent_at' => 'immutable_datetime',
            'reminder_j1_sent_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
            'amount_gross' => 'integer',
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'months_covered' => 'integer',
        ];
    }

    /** Reste à payer d'un paiement (RG-OBL-08) ; nul tant que le montant dû n'est pas connu. */
    public function balance(): ?int
    {
        return $this->amount_due === null ? null : max(0, $this->amount_due - $this->amount_paid);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<MemberObligation, $this>
     */
    public function memberObligation(): BelongsTo
    {
        return $this->belongsTo(MemberObligation::class);
    }

    /**
     * @return BelongsTo<ObligationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ObligationType::class, 'obligation_type_id');
    }

    /**
     * Paiement créé depuis une déclaration faite (RG-OBL-05).
     *
     * @return BelongsTo<ObligationInstance, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_instance_id');
    }

    /**
     * @return HasMany<ObligationPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ObligationPayment::class, 'instance_id');
    }

    protected static function newFactory(): ObligationInstanceFactory
    {
        return ObligationInstanceFactory::new();
    }
}
