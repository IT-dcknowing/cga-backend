<?php

namespace App\Domain\Closing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Closing\Enums\ClosingDeliverable;
use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClosingCampaignItemFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avancement d'un dossier dans la campagne de clôture (F-OBL-08, RG-OBL-10) : date de chaque étape
 * faite, dans l'ordre ; référence et accusé du dépôt légal ; livrable (SMT ou états financiers) ; agent
 * suivi. Le portefeuille est copié à l'ouverture : seuls les dossiers du portefeuille CGA comptent
 * pour le rapport trimestriel (RG-OBL-12, scope regulatory).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $campaign_id
 * @property string $member_id
 * @property Portfolio $portfolio
 * @property ClosingDeliverable $deliverable
 * @property CarbonInterface|null $step_pieces_at
 * @property CarbonInterface|null $step_regularisation_at
 * @property CarbonInterface|null $step_finalisation_at
 * @property CarbonInterface|null $step_filing_at
 * @property string|null $filing_reference
 * @property string|null $filing_file_id
 * @property string|null $assigned_to
 * @property CarbonInterface|null $last_reminder_at
 * @property-read ClosingCampaign $campaign
 * @property-read Member $member
 * @property-read File|null $filingFile
 * @property-read User|null $assignee
 */
class ClosingCampaignItem extends Model
{
    /** @use HasFactory<ClosingCampaignItemFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUlids;

    /**
     * Écrit par les Actions de la clôture seulement.
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
            'portfolio' => Portfolio::class,
            'deliverable' => ClosingDeliverable::class,
            'step_pieces_at' => 'immutable_date',
            'step_regularisation_at' => 'immutable_date',
            'step_finalisation_at' => 'immutable_date',
            'step_filing_at' => 'immutable_date',
            'last_reminder_at' => 'immutable_datetime',
        ];
    }

    public function doneOn(ClosingStep $step): ?CarbonImmutable
    {
        $date = $this->getAttribute($step->column());

        return $date === null ? null : CarbonImmutable::parse($date);
    }

    /** Première étape encore à faire, dans l'ordre ; nulle quand le dépôt est fait. */
    public function nextStep(): ?ClosingStep
    {
        foreach (ClosingStep::cases() as $step) {
            if ($this->doneOn($step) === null) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Motif de refus de « Marquer l'étape faite » (décision 0006) : étape déjà faite, ou une étape
     * précédente reste à faire ; nul si l'étape peut être marquée.
     *
     * @return 'closing_step_already_done'|'closing_step_out_of_order'|null
     */
    public function stepRefusal(ClosingStep $step): ?string
    {
        if ($this->doneOn($step) !== null) {
            return 'closing_step_already_done';
        }

        return $this->nextStep() === $step ? null : 'closing_step_out_of_order';
    }

    /**
     * Dossiers du portefeuille CGA, seuls retenus pour le rapport trimestriel (RG-OBL-12, RG-SEC-08).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function regulatory(Builder $query): void
    {
        $query->where('portfolio', Portfolio::Cga);
    }

    /**
     * @return BelongsTo<ClosingCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ClosingCampaign::class, 'campaign_id');
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
    public function filingFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'filing_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected static function newFactory(): ClosingCampaignItemFactory
    {
        return ClosingCampaignItemFactory::new();
    }
}
