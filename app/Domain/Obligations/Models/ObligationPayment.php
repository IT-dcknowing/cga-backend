<?php

namespace App\Domain\Obligations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Models\Member;
use App\Domain\Obligations\Enums\ObligationPaymentSource;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement d'une échéance (05-BACKEND-SCHEMA §7) : saisi par le CGA, déclaré par l'adhérent puis
 * confirmé (RG-OBL-09), importé ou reçu de Compta Flow (confirmé d'office et verrouillé, RG-DATA-06).
 * Les paiements confirmés alimentent les recettes fiscales et le rapport trimestriel.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $instance_id
 * @property string $member_id
 * @property TaxNature $tax_nature
 * @property int $amount
 * @property CarbonInterface $paid_on
 * @property ObligationPaymentSource $source
 * @property string|null $external_ref
 * @property PaymentStatus $status
 * @property string|null $proof_file_id
 * @property string|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property string|null $recorded_by
 * @property-read ObligationInstance $instance
 * @property-read File|null $proof
 */
class ObligationPayment extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * Écrit par les Actions des obligations seulement.
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
            'tax_nature' => TaxNature::class,
            'source' => ObligationPaymentSource::class,
            'status' => PaymentStatus::class,
            'paid_on' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ObligationInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(ObligationInstance::class, 'instance_id');
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
}
