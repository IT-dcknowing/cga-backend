<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Referentials\Enums\DataStatus;
use Illuminate\Support\Facades\DB;

/**
 * Obligation locale d'un CGA (RG-OBL-01) : créée ou modifiée par le centre pour son propre usage, à
 * côté du catalogue national qu'il ne modifie jamais. Une obligation locale se désactive ; elle ne se
 * supprime pas (ses échéances gardent leur historique).
 */
class SaveLocalObligationType
{
    /**
     * @param  array<string, mixed>  $fields  saisie validée
     */
    public function execute(?ObligationType $type, string $organizationId, array $fields): ObligationType
    {
        return DB::transaction(static function () use ($type, $organizationId, $fields): ObligationType {
            $type ??= new ObligationType;

            if (! $type->exists) {
                $type->organization_id = $organizationId;
                $type->catalog_version = (string) config('cga.obligations.local_catalog_version');
                $type->data_status = DataStatus::Validated;
                $type->validated_at = now()->toImmutable();
            }

            $type->code = $fields['code'];
            $type->label = $fields['label'];
            $type->category = ObligationCategory::from($fields['category']);
            $type->authority = $fields['authority'];
            $type->platform = $fields['platform'] ?? null;
            $type->kind = ObligationKind::from($fields['kind']);
            $type->periodicity = ObligationPeriodicity::from($fields['periodicity']);
            $type->due_rule = $fields['due_rule'];
            $type->applicability = [
                'regimes' => $fields['applicability']['regimes'] ?? [],
                'min_employees' => $fields['applicability']['min_employees'] ?? null,
                'sectors' => $fields['applicability']['sectors'] ?? [],
            ];
            $type->tax_nature = isset($fields['tax_nature']) ? TaxNature::from($fields['tax_nature']) : null;
            $type->has_abatement = (bool) ($fields['has_abatement'] ?? false);
            $type->creates_payment_on_done = (bool) ($fields['creates_payment_on_done'] ?? true);
            $type->active = (bool) ($fields['active'] ?? true);
            $type->legal_basis = $fields['legal_basis'] ?? null;
            $type->save();

            return $type;
        });
    }
}
