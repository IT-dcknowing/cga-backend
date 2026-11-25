<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Data\RowOutcome;
use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\HistoryValues;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Imports\Support\MemberLookup;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\RevenueSource;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberRevenue;
use Carbon\CarbonImmutable;

/**
 * Import de l'historique des chiffres d'affaires (F-IMP-03, 06-IMPLEMENTATION-PLAN étape 5.6) : par ligne,
 * le dossier (n° ou NCC), l'année, le montant et le genre (réalisé par défaut, ou prévisionnel). Le CA
 * repris alimente le CA de référence et les surclassements du rapport (N-3 à N-1). Une valeur déjà
 * connue est remplacée (modification tracée au journal) ; une valeur venue de Compta Flow fait foi et
 * n'est jamais remplacée (RG-DATA-06) : la ligne est rejetée.
 */
class RevenueRowImporter implements RowImporter
{
    public function columns(): array
    {
        return [
            'member' => ['label' => 'N° d\'adhérent ou NCC *', 'help' => __('imports.history.help.member'), 'aliases' => ['N° adhérent', 'Numéro', 'NCC', 'Dossier']],
            'year' => ['label' => 'Année *', 'help' => __('imports.revenues.help.year'), 'aliases' => ['Année', 'Exercice']],
            'amount' => ['label' => 'Chiffre d\'affaires *', 'help' => __('imports.revenues.help.amount'), 'aliases' => ['Chiffre d\'affaires', 'CA', 'Montant']],
            'kind' => ['label' => 'Nature', 'help' => __('imports.revenues.help.kind'), 'aliases' => ['Type', 'Réalisé ou prévisionnel']],
        ];
    }

    public function requiredColumns(): array
    {
        return ['member', 'year', 'amount'];
    }

    public function labelColumn(): string
    {
        return 'member';
    }

    public function fileName(): string
    {
        return 'import-historique-ca';
    }

    public function import(array $values): RowOutcome
    {
        $designation = CellValue::text($values['member'] ?? null);
        $year = HistoryValues::integer($values['year'] ?? null);
        $amount = HistoryValues::integer($values['amount'] ?? null);
        $kindText = CellValue::text($values['kind'] ?? null);
        $kind = $kindText === null ? RevenueKind::Actual : $this->kind($kindText);
        $member = $designation === null ? null : MemberLookup::find($designation);
        $lastYear = CarbonImmutable::now()->year + ($kind === RevenueKind::Forecast ? 1 : 0);

        $reasons = array_values(array_filter([
            $designation === null ? __('imports.history.required', ['field' => 'N° d\'adhérent ou NCC']) : null,
            $designation !== null && $member === null ? __('imports.history.unknown_member', ['value' => $designation]) : null,
            $member === false ? __('imports.history.ambiguous_member', ['value' => $designation]) : null,
            $year === null ? __('imports.revenues.invalid_year') : null,
            $year !== null && ($year < (int) config('imports.history.first_year') || $year > $lastYear) ? __('imports.revenues.year_out_of_range', ['first' => config('imports.history.first_year'), 'last' => $lastYear]) : null,
            $amount === null || $amount < 0 ? __('imports.history.invalid_amount', ['field' => 'Chiffre d\'affaires']) : null,
            $kind === null ? __('imports.revenues.invalid_kind', ['value' => $kindText]) : null,
        ]));
        if ($reasons !== [] || ! $member instanceof Member || $year === null || $amount === null || $kind === null) {
            return RowOutcome::rejected($designation, $reasons === [] ? [(string) __('imports.cgas.invalid_row')] : $reasons);
        }

        $revenue = MemberRevenue::query()->where('member_id', $member->id)->where('year', $year)->where('kind', $kind)->lockForUpdate()->first();
        if ($revenue?->source === RevenueSource::ComptaFlow) {
            return RowOutcome::rejected($designation, [__('imports.history.compta_flow')]);
        }
        $revenue ??= new MemberRevenue;
        $revenue->organization_id = $member->organization_id;
        $revenue->member_id = $member->id;
        $revenue->year = $year;
        $revenue->kind = $kind;
        $revenue->amount = $amount;
        $revenue->source = RevenueSource::Import;
        $revenue->save();

        return RowOutcome::imported($revenue->id, $member->company_name);
    }

    private function kind(string $text): ?RevenueKind
    {
        return match (ImportWorkbook::normalize($text)) {
            'realise', 'reel', 'actual' => RevenueKind::Actual,
            'previsionnel', 'prevision', 'forecast' => RevenueKind::Forecast,
            default => null,
        };
    }
}
