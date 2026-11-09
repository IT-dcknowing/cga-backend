<?php

namespace App\Domain\Obligations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Referentials\Contracts\Referential;
use App\Domain\Referentials\Enums\DataStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Database\Factories\ObligationTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Obligation du catalogue (F-OBL-01, 05-BACKEND-SCHEMA §4) : nationale (`organization_id` nul), gérée
 * par le CNCGA et versionnée (`catalog_version`, RG-DATA-04), ou locale à un CGA (RG-OBL-01), qui ne
 * modifie jamais le catalogue national. Donnée de référence provisoire jusqu'à sa validation.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $catalog_version
 * @property string $code
 * @property string $label
 * @property ObligationCategory $category
 * @property string $authority
 * @property string|null $platform
 * @property ObligationKind $kind
 * @property ObligationPeriodicity $periodicity
 * @property array<string, mixed> $due_rule
 * @property array<string, mixed> $applicability
 * @property TaxNature|null $tax_nature
 * @property bool $has_abatement
 * @property bool $creates_payment_on_done
 * @property bool $active
 * @property string|null $legal_basis
 * @property DataStatus $data_status
 * @property CarbonInterface|null $validated_at
 */
class ObligationType extends Model implements Referential
{
    /** @use HasFactory<ObligationTypeFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * Écrit par le chargement du catalogue et les Actions des obligations seulement.
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
            'category' => ObligationCategory::class,
            'kind' => ObligationKind::class,
            'periodicity' => ObligationPeriodicity::class,
            'tax_nature' => TaxNature::class,
            'due_rule' => 'array',
            'applicability' => 'array',
            'has_abatement' => 'boolean',
            'creates_payment_on_done' => 'boolean',
            'active' => 'boolean',
            'data_status' => DataStatus::class,
            'validated_at' => 'immutable_datetime',
        ];
    }

    public static function referencedBy(): array
    {
        return ['member_obligations' => 'obligation_type_id', 'obligation_instances' => 'obligation_type_id'];
    }

    public function isNational(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * Catalogue national, plus les obligations locales du CGA de l'espace actif (RG-OBL-01) ; jamais
     * celles d'un autre CGA.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleIn(Builder $query, TenantContext $context): void
    {
        $query->where(static function (Builder $visible) use ($context): void {
            $visible->whereNull('organization_id');

            if ($context->organizationType() === OrganizationType::Cga) {
                $visible->orWhere('organization_id', $context->organizationId());
            }
        });
    }

    /**
     * Obligations proposables à un CGA : le catalogue national en vigueur (actif) et ses propres
     * obligations locales actives (RG-OBL-01).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inForceFor(Builder $query, string $organizationId): void
    {
        $query->where('active', true)->where(static function (Builder $own) use ($organizationId): void {
            $own->whereNull('organization_id')->orWhere('organization_id', $organizationId);
        });
    }

    protected static function newFactory(): ObligationTypeFactory
    {
        return ObligationTypeFactory::new();
    }
}
