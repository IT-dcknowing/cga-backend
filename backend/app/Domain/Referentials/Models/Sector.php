<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Database\Factories\SectorFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Secteur d'activité (F-REF-01, RG-REF-01).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property SectorStatus $status
 * @property string|null $proposed_by_organization_id
 * @property string|null $merged_into_id
 * @property string|null $validated_by
 * @property CarbonInterface|null $validated_at
 * @property DataStatus $data_status
 */
class Sector extends Model implements Referential
{
    /** @use HasFactory<SectorFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * Le statut, l'auteur de la proposition et la validation sont fixés par les Actions.
     *
     * @var list<string>
     */
    protected $fillable = ['code', 'name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SectorStatus::class,
            'data_status' => DataStatus::class,
            'validated_at' => 'immutable_datetime',
        ];
    }

    public static function referencedBy(): array
    {
        return ['sectors' => 'merged_into_id'];
    }

    /**
     * Secteurs visibles dans l'espace actif : les validés pour tous ; tous pour le CNCGA et la
     * plateforme ; pour un CGA, en plus, ceux qu'il a proposés.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleIn(Builder $query, TenantContext $context): void
    {
        if ($context->organizationType() !== OrganizationType::Cga) {
            return;
        }

        $query->where(static function (Builder $visible) use ($context): void {
            $visible->where('status', SectorStatus::Validated)
                ->orWhere(static function (Builder $proposed) use ($context): void {
                    $proposed->where('status', SectorStatus::Proposed)->where('proposed_by_organization_id', $context->organizationId());
                });
        });
    }

    protected static function newFactory(): SectorFactory
    {
        return SectorFactory::new();
    }
}
