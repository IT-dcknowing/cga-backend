<?php

namespace App\Domain\Obligations\Actions;

use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Obligations\Enums\ObligationCategory;
use App\Domain\Obligations\Enums\ObligationKind;
use App\Domain\Obligations\Enums\ObligationPeriodicity;
use App\Domain\Obligations\Enums\TaxNature;
use App\Domain\Obligations\Models\ObligationType;
use App\Domain\Obligations\Support\DueRule;
use App\Domain\Referentials\Enums\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chargement d'une version du catalogue national des obligations (F-OBL-01, étape 4.1) depuis le
 * référentiel fourni (JSON) : chaque obligation est contrôlée avant toute écriture ; le chargement est
 * transactionnel et rejouable (une obligation déjà présente dans la version est mise à jour, jamais
 * dupliquée). Les obligations chargées sont provisoires jusqu'à leur validation par le CNCGA
 * (RG-DATA-02) ; les obligations locales des CGA ne sont jamais touchées (RG-OBL-01).
 *
 * Format attendu :
 * `{"version": "2026.1", "obligations": [{"code", "label", "category", "authority", "platform",
 * "kind", "periodicity", "due_rule", "applicability", "tax_nature", "has_abatement",
 * "creates_payment_on_done", "legal_basis"}]}`.
 */
class LoadObligationCatalog
{
    /**
     * @param  array<string, mixed>  $catalog
     * @return int nombre d'obligations chargées
     *
     * @throws ValidationException catalogue invalide : rien n'est écrit
     */
    public function execute(array $catalog): int
    {
        $validated = Validator::make($catalog, $this->rules(), [], ['version' => __('obligations.catalog_version')])->validate();
        $version = (string) $validated['version'];

        return DB::transaction(static function () use ($validated, $version): int {
            foreach ($validated['obligations'] as $entry) {
                $type = ObligationType::query()
                    ->whereNull('organization_id')
                    ->where('catalog_version', $version)
                    ->where('code', $entry['code'])
                    ->first() ?? new ObligationType;

                $type->organization_id = null;
                $type->catalog_version = $version;
                $type->code = $entry['code'];
                $type->label = $entry['label'];
                $type->category = ObligationCategory::from($entry['category']);
                $type->authority = $entry['authority'];
                $type->platform = $entry['platform'] ?? null;
                $type->kind = ObligationKind::from($entry['kind']);
                $type->periodicity = ObligationPeriodicity::from($entry['periodicity']);
                $type->due_rule = $entry['due_rule'];
                $type->applicability = [
                    'regimes' => $entry['applicability']['regimes'] ?? [],
                    'min_employees' => $entry['applicability']['min_employees'] ?? null,
                    'sectors' => $entry['applicability']['sectors'] ?? [],
                ];
                $type->tax_nature = isset($entry['tax_nature']) ? TaxNature::from($entry['tax_nature']) : null;
                $type->has_abatement = (bool) ($entry['has_abatement'] ?? false);
                $type->creates_payment_on_done = (bool) ($entry['creates_payment_on_done'] ?? true);
                $type->legal_basis = $entry['legal_basis'] ?? null;
                $type->active = true;

                if (! $type->exists) {
                    $type->data_status = DataStatus::Provisional;
                }

                $type->save();
            }

            return count($validated['obligations']);
        });
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:20'],
            'obligations' => ['required', 'array', 'min:1'],
            'obligations.*.code' => ['required', 'string', 'max:20', 'distinct'],
            'obligations.*.label' => ['required', 'string', 'max:200'],
            'obligations.*.category' => ['required', Rule::enum(ObligationCategory::class)],
            'obligations.*.authority' => ['required', 'string', 'max:100'],
            'obligations.*.platform' => ['nullable', 'string', 'max:50'],
            'obligations.*.kind' => ['required', Rule::enum(ObligationKind::class)],
            'obligations.*.periodicity' => ['required', Rule::enum(ObligationPeriodicity::class)],
            ...DueRule::rules('obligations.*.due_rule'),
            'obligations.*.applicability' => ['required', 'array'],
            'obligations.*.applicability.regimes' => ['present', 'array'],
            'obligations.*.applicability.regimes.*' => [Rule::enum(TaxRegime::class)],
            'obligations.*.applicability.min_employees' => ['nullable', 'integer', 'min:0'],
            'obligations.*.applicability.sectors' => ['present', 'array'],
            'obligations.*.applicability.sectors.*' => ['string', 'max:10'],
            'obligations.*.tax_nature' => ['nullable', Rule::enum(TaxNature::class)],
            'obligations.*.has_abatement' => ['sometimes', 'boolean'],
            'obligations.*.creates_payment_on_done' => ['sometimes', 'boolean'],
            'obligations.*.legal_basis' => ['nullable', 'string'],
        ];
    }
}
