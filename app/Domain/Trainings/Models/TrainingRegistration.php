<?php

namespace App\Domain\Trainings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingPaymentStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscription d'une personne à une formation (F-FOR-02 à 04), dans l'espace où elle est ciblée
 * (membership_id : agent d'un CGA ou accès adhérent). Cloisonnée au CGA de la personne ; l'organisateur
 * d'une formation du réseau la lit par un contournement nommé (TrainingAccess).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $training_id
 * @property string $membership_id
 * @property string $user_id
 * @property string|null $member_id
 * @property string $registered_by
 * @property RegistrationStatus $status
 * @property int|null $waitlist_position
 * @property string|null $rejection_reason
 * @property TrainingPaymentStatus $payment_status
 * @property CarbonInterface|null $paid_on
 * @property PaymentMethod|null $payment_method
 * @property string|null $payment_reference
 * @property string|null $receipt_number
 * @property string|null $receipt_document_id
 * @property Attendance|null $attendance
 * @property string|null $certificate_document_id
 * @property int|null $evaluation_score
 * @property string|null $evaluation_comment
 * @property CarbonInterface|null $evaluated_at
 * @property CarbonInterface|null $promoted_at
 * @property CarbonInterface|null $reminded_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface $created_at
 * @property-read Training $training
 * @property-read Membership $membership
 * @property-read User $user
 * @property-read Member|null $member
 * @property-read GeneratedDocument|null $receipt
 * @property-read GeneratedDocument|null $certificate
 */
class TrainingRegistration extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * Écrit par les Actions des formations seulement.
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
            'status' => RegistrationStatus::class,
            'waitlist_position' => 'integer',
            'payment_status' => TrainingPaymentStatus::class,
            'paid_on' => 'immutable_date',
            'payment_method' => PaymentMethod::class,
            'attendance' => Attendance::class,
            'evaluation_score' => 'integer',
            'evaluated_at' => 'immutable_datetime',
            'promoted_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Training, $this>
     */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Reçu du paiement (RG-FOR-06), document de l'organisateur.
     *
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'receipt_document_id');
    }

    /**
     * Attestation de participation (F-FOR-04), document de l'organisateur.
     *
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'certificate_document_id');
    }
}
