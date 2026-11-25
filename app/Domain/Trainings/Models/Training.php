<?php

namespace App\Domain\Trainings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Trainings\Enums\TrainingFormat;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\TrainingFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formation ou séminaire (PRD M11, F-FOR-01), cloisonnée à son organisateur : le CNCGA (formation du
 * réseau) ou un CGA (ses agents et adhérents, RG-FOR-01). Les CGA et les personnes ciblées lisent une
 * formation du réseau par un contournement nommé (TrainingAccess).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string|null $description
 * @property TrainingFormat $format
 * @property string|null $location
 * @property string|null $online_url
 * @property string|null $trainer
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property int|null $capacity
 * @property int $price
 * @property bool $requires_approval
 * @property TrainingStatus $status
 * @property CarbonInterface|null $published_at
 * @property string|null $cancellation_reason
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $completed_at
 * @property string $created_by
 * @property-read Organization $organization
 * @property-read Collection<int, TrainingAudience> $audiences
 * @property-read Collection<int, TrainingRegistration> $registrations
 */
class Training extends Model
{
    /** @use HasFactory<TrainingFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

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
            'format' => TrainingFormat::class,
            'status' => TrainingStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'price' => 'integer',
            'requires_approval' => 'boolean',
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->price > 0;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<TrainingAudience, $this>
     */
    public function audiences(): HasMany
    {
        return $this->hasMany(TrainingAudience::class)->orderBy('id');
    }

    /**
     * @return HasMany<TrainingRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(TrainingRegistration::class);
    }

    protected static function newFactory(): TrainingFactory
    {
        return TrainingFactory::new();
    }
}
