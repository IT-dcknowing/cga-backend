<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\RevenueKind;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Rules\RegimeOfPortfolio;
use App\Domain\Members\Rules\ValidNcc;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Support\Phones\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fiche d'un dossier (F-ADH-02, étapes 1 et 2), commune à l'inscription et à la modification. Un
 * brouillon peut être incomplet : seuls les champs sans lesquels le dossier n'existe pas sont exigés
 * ici ; les autres le sont à la validation de l'adhésion (RG-ADH-02). Portefeuille CGA : régime RME ou
 * TEE, apporteur et contrat CGA possibles ; portefeuille Cabinet : les quatre régimes, ni apporteur ni
 * contrat CGA (RG-PTF-06, 08).
 */
abstract class MemberRequest extends FormRequest
{
    /**
     * Portefeuille du dossier modifié ; null à l'inscription, où il fait partie de la saisie.
     */
    abstract protected function fixedPortfolio(): ?Portfolio;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones, TenantContext $context): array
    {
        $portfolio = $this->fixedPortfolio();
        $organizationId = $context->organizationId();
        $onlyCga = match ($portfolio) {
            null => ['nullable', 'prohibited_if:portfolio,'.Portfolio::Cabinet->value],
            Portfolio::Cga => ['nullable'],
            Portfolio::Cabinet => ['prohibited'],
        };

        return [
            'entity_type' => ['required', Rule::enum(EntityType::class)],
            'company_name' => ['required', 'string', 'max:200'],
            'sigle' => ['nullable', 'string', 'max:50'],
            'legal_form' => ['required', Rule::enum(LegalForm::class)],
            'share_capital' => ['nullable', 'integer', 'min:0'],
            'owner_last_name' => ['nullable', 'string', 'max:150'],
            'owner_first_names' => ['nullable', 'string', 'max:150'],
            'owner_nationality' => ['nullable', 'string', 'max:80'],
            'owner_id_document_type' => ['nullable', 'string', 'max:30'],
            'owner_id_document_number' => ['nullable', 'string', 'max:50'],
            'ncc' => ['required', 'string', 'max:20', new ValidNcc],
            'rccm' => ['nullable', 'string', 'max:50'],
            'idu' => ['nullable', 'string', 'max:50'],
            'regime' => ['required', Rule::enum(TaxRegime::class), new RegimeOfPortfolio($portfolio)],
            'regime_since' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'legacy_number' => ['nullable', 'string', 'max:60'],
            'referrer_id' => [...$onlyCga, 'string', Rule::exists('business_referrers', 'id')->where('organization_id', $organizationId)->where('active', true)],
            'tax_office_id' => ['nullable', 'string', Rule::exists('tax_offices', 'id')->where('active', true)],
            'activity_code_id' => ['nullable', 'string', Rule::exists('activity_codes', 'id')->where('active', true)],
            'activity_code_text' => ['nullable', 'string', 'max:20'],
            'sector_id' => ['nullable', 'string', Rule::exists('sectors', 'id')->where(static fn (Builder $sector) => $sector
                ->where('status', SectorStatus::Validated->value)
                ->orWhere(static fn (Builder $proposed) => $proposed->where('status', SectorStatus::Proposed->value)->where('proposed_by_organization_id', $organizationId)))],
            'main_activity' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'commune_id' => ['nullable', 'string', Rule::exists('communes', 'id')],
            'land_section' => ['nullable', 'string', 'max:30'],
            'land_parcel' => ['nullable', 'string', 'max:30'],
            'registration_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['required', 'string', 'max:30', $phones->rule()],
            'whatsapp' => ['nullable', 'string', 'max:30', $phones->rule()],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'signatory_name' => ['nullable', 'string', 'max:150'],
            'signatory_title' => ['nullable', 'string', 'max:100'],
            'employees_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'cga_contract_signed_on' => [...$onlyCga, 'date_format:Y-m-d', 'before_or_equal:today'],
            'cga_contract_confirmed' => [...$onlyCga, 'boolean'],
            'entry_fee_amount' => ['nullable', 'integer', 'min:0'],
            'fees_amount' => ['nullable', 'integer', 'min:0'],
            'fees_periodicity' => ['nullable', Rule::enum(FeesPeriodicity::class)],
            'reference_revenue' => ['nullable', 'array:year,amount,kind'],
            'reference_revenue.year' => ['required_with:reference_revenue', 'integer', 'min:2000', 'max:'.(now()->year + 1)],
            'reference_revenue.amount' => ['required_with:reference_revenue', 'integer', 'min:0'],
            'reference_revenue.kind' => ['required_with:reference_revenue', Rule::enum(RevenueKind::class)],
        ];
    }

    /**
     * Champs de la fiche, sans le chiffre d'affaires de référence ni la confirmation du contrat.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return array_diff_key($this->validated(), ['reference_revenue' => true, 'cga_contract_confirmed' => true, 'portfolio' => true, 'justification' => true, 'transfer_id' => true]);
    }

    /**
     * @return array{year: int, amount: int, kind: string}|null
     */
    public function revenue(): ?array
    {
        $revenue = $this->validated('reference_revenue');

        return is_array($revenue) ? ['year' => (int) $revenue['year'], 'amount' => (int) $revenue['amount'], 'kind' => (string) $revenue['kind']] : null;
    }

    public function contractConfirmed(): ?bool
    {
        $confirmed = $this->validated('cga_contract_confirmed');

        return $confirmed === null ? null : (bool) $confirmed;
    }
}
