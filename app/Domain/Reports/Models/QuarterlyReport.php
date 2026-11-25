<?php

namespace App\Domain\Reports\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Enums\ReportStatus;
use App\Domain\Reports\Enums\TransmissionMode;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\QuarterlyReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rapport trimestriel d'un CGA (PRD M09), un par année et trimestre : contenu saisi (sections 4 et 6),
 * impôts du trimestre des dirigeants (section 2), analyse qualitative, complétude par section
 * (RG-RPT-02), échéances J et J+15 (RG-RPT-06, 07), transmission et accusé (F-RPT-04, 05), tableau de
 * synthèse seul visible du CNCGA (RG-RPT-04).
 *
 * @property string $id
 * @property string $organization_id
 * @property int $year
 * @property int $quarter
 * @property ReportStatus $status
 * @property CarbonInterface $deadline
 * @property CarbonInterface $legal_deadline
 * @property bool $is_late
 * @property bool $is_legally_late
 * @property list<int> $reminders_sent
 * @property array<string, string|null> $qualitative_analysis
 * @property CarbonInterface|null $report_date
 * @property string|null $regional_directorate_label
 * @property array<string, mixed> $content
 * @property list<array{person_id: string, its: int|null, igr: int|null, cnps: int|null}> $people_taxes
 * @property array<string, int> $completeness
 * @property int $current_version
 * @property CarbonInterface|null $transmitted_at
 * @property TransmissionMode|null $transmission_mode
 * @property string|null $transmission_reference
 * @property string|null $transmitted_by
 * @property CarbonInterface|null $acknowledged_at
 * @property string|null $acknowledgement_reference
 * @property string|null $acknowledgement_file_id
 * @property array<string, mixed>|null $summary
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Organization $organization
 * @property-read File|null $acknowledgementFile
 * @property-read User|null $transmitter
 */
class QuarterlyReport extends Model
{
    /** @use HasFactory<QuarterlyReportFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions du rapport seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reminders_sent' => '[]',
        'qualitative_analysis' => '{}',
        'content' => '{}',
        'people_taxes' => '[]',
        'completeness' => '{}',
        'is_late' => false,
        'is_legally_late' => false,
        'current_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'status' => ReportStatus::class,
            'deadline' => 'immutable_date',
            'legal_deadline' => 'immutable_date',
            'is_late' => 'boolean',
            'is_legally_late' => 'boolean',
            'reminders_sent' => 'array',
            'qualitative_analysis' => 'array',
            'report_date' => 'immutable_date',
            'content' => 'array',
            'people_taxes' => 'array',
            'completeness' => 'array',
            'current_version' => 'integer',
            'transmitted_at' => 'immutable_datetime',
            'transmission_mode' => TransmissionMode::class,
            'acknowledged_at' => 'immutable_date',
            'summary' => 'array',
        ];
    }

    /** Premier jour du trimestre. */
    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, ($this->quarter - 1) * 3 + 1, 1)->startOfDay();
    }

    /** Dernier jour du trimestre : date d'arrêté des états du portefeuille (PRD M09). */
    public function periodEnd(): CarbonImmutable
    {
        return $this->periodStart()->addMonthsNoOverflow(2)->endOfMonth()->startOfDay();
    }

    /** Le contenu se modifie tant que le rapport n'est pas transmis (une correction ouvre une version). */
    public function isEditable(): bool
    {
        return in_array($this->status, [ReportStatus::Draft, ReportStatus::Generated], true);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<QuarterlyReportVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QuarterlyReportVersion::class, 'report_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function acknowledgementFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'acknowledgement_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function transmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transmitted_by');
    }

    protected static function newFactory(): QuarterlyReportFactory
    {
        return QuarterlyReportFactory::new();
    }
}
