<?php

namespace App\Domain\Payments\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\MemberPaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paiement d'un adhérent à son CGA (F-PAY-03, 04) : saisi par le CGA (confirmé d'office) ou déclaré
 * par l'adhérent avec sa preuve (à confirmer), ventilé sur les sommes dues ; reçu numéroté.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property int $amount
 * @property CarbonInterface $paid_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $proof_file_id
 * @property PaymentSource $source
 * @property PaymentStatus $status
 * @property string|null $rejection_reason
 * @property string|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property string|null $receipt_number
 * @property string|null $receipt_document_id
 * @property string|null $recorded_by
 * @property-read Member $member
 * @property-read File|null $proof
 * @property-read GeneratedDocument|null $receipt
 */
class MemberPayment extends Model
{
    /** @use HasFactory<MemberPaymentFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des paiements seulement.
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
            'method' => PaymentMethod::class,
            'source' => PaymentSource::class,
            'status' => PaymentStatus::class,
            'paid_on' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(File::class, 'proof_file_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'receipt_document_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    protected static function newFactory(): MemberPaymentFactory
    {
        return MemberPaymentFactory::new();
    }
}
