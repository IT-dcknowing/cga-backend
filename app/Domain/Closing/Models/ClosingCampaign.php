<?php

namespace App\Domain\Closing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Closing\Enums\ClosingCampaignStatus;
use App\Domain\Closing\Enums\ClosingStep;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClosingCampaignFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campagne de clôture d'un exercice dans un CGA (F-OBL-08) : calendrier des 4 étapes figé à
 * l'ouverture (RG-OBL-10), un suivi par dossier actif des portefeuilles CGA et Cabinet.
 *
 * @property string $id
 * @property string $organization_id
 * @property int $fiscal_year
 * @property list<array{code: string, due: string}> $steps
 * @property ClosingCampaignStatus $status
 * @property CarbonInterface $opened_at
 * @property CarbonInterface|null $closed_at
 */
class ClosingCampaign extends Model
{
    /** @use HasFactory<ClosingCampaignFactory> */
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
            'fiscal_year' => 'integer',
            'steps' => 'array',
            'status' => ClosingCampaignStatus::class,
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** Date de l'étape dans le calendrier figé de la campagne. */
    public function due(ClosingStep $step): CarbonImmutable
    {
        foreach ($this->steps as $entry) {
            if ($entry['code'] === $step->value) {
                return CarbonImmutable::parse($entry['due']);
            }
        }

        throw new \LogicException("Étape {$step->value} absente du calendrier de la campagne {$this->id}.");
    }

    /**
     * @return HasMany<ClosingCampaignItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ClosingCampaignItem::class, 'campaign_id');
    }

    protected static function newFactory(): ClosingCampaignFactory
    {
        return ClosingCampaignFactory::new();
    }
}
