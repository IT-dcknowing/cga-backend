<?php

namespace App\Domain\Exports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export Excel demandé dans un espace (F-EXP-01) : liste, période et filtres, accès de la personne qui exporte
 * (son périmètre, RG-SEC-06), avancement, nombre de lignes, fichier horodaté. Cloisonné à l'espace ; écrit par
 * les Actions des exports seulement.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $user_id
 * @property ExportType $type
 * @property string|null $period_type
 * @property CarbonInterface|null $period_from
 * @property CarbonInterface|null $period_to
 * @property array<string, mixed> $filters
 * @property ExportStatus $status
 * @property int $rows_count
 * @property string|null $file_id
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $finished_at
 * @property-read Membership $membership
 * @property-read User $user
 * @property-read File|null $file
 */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions des exports seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'filters' => '{}',
        'status' => 'queued',
        'rows_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'filters' => 'array',
            'status' => ExportStatus::class,
            'rows_count' => 'integer',
            'requested_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ExportFactory
    {
        return ExportFactory::new();
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
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
