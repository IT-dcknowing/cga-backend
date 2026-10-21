<?php

namespace App\Domain\Members\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Enums\RadiationInitiator;
use App\Domain\Members\Enums\RadiationOutcome;
use App\Domain\Members\Enums\RadiationReason;
use App\Domain\Members\Enums\RadiationStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Radiation d'un dossier du portefeuille CGA, en deux temps (RG-ADH-17) : demandée, puis validée
 * manuellement (RG-ADH-18), ou annulée dans les 30 jours (RG-ADH-11). L'observation est chiffrée.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $member_id
 * @property RadiationInitiator $initiator
 * @property RadiationReason $reason
 * @property string|null $reason_other
 * @property RadiationStatus $status
 * @property CarbonInterface $effective_date
 * @property string $observation
 * @property string|null $proof_file_id
 * @property string|null $sp_request_reference
 * @property string $requested_by
 * @property CarbonInterface $requested_at
 * @property string|null $validated_by
 * @property CarbonInterface|null $validated_at
 * @property string|null $validation_reference
 * @property RadiationOutcome|null $outcome
 * @property int|null $report_year
 * @property int|null $report_quarter
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 * @property-read Member $member
 * @property-read File|null $proof
 */
class Radiation extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * Écrit par les Actions du dossier seulement.
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
            'initiator' => RadiationInitiator::class,
            'reason' => RadiationReason::class,
            'status' => RadiationStatus::class,
            'outcome' => RadiationOutcome::class,
            'observation' => 'encrypted',
            'effective_date' => 'immutable_date',
            'requested_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'report_year' => 'integer',
            'report_quarter' => 'integer',
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
}
