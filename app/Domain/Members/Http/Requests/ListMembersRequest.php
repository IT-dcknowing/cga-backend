<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\PiecesStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Support\Ncc;
use App\Support\Http\ListRequest;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\Rules\EnumList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/cga/members : adhérents du centre (F-ADH-01), par portefeuille ou tous ; recherche par
 * raison sociale, sigle, NCC, numéros (d'adhésion, client, ancien) ou téléphone ; filtres par statuts
 * (onglet « Radiés / clos » : radiated,closed), régime, secteur, CDI, pièces, apporteur, date
 * d'adhésion.
 */
class ListMembersRequest extends ListRequest
{
    /**
     * « Tous » du sélecteur de portefeuille (03-APPFLOW §4.3) : les deux portefeuilles.
     */
    public const ALL_PORTFOLIOS = 'all';

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Member::class);
    }

    /**
     * Portefeuilles de la liste (CGA par défaut) : celui du Cabinet, seul ou avec « Tous », demande
     * members.cabinet.view.
     *
     * @return list<Portfolio>
     */
    public function portfolios(): array
    {
        $portfolio = (string) ($this->validated('filter.portfolio') ?? Portfolio::Cga->value);

        return $portfolio === self::ALL_PORTFOLIOS ? Portfolio::cases() : [Portfolio::from($portfolio)];
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'portfolio' => [Rule::in([...array_column(Portfolio::cases(), 'value'), self::ALL_PORTFOLIOS])],
            'status' => ['string', 'max:100', new EnumList(MemberStatus::class)],
            'regime' => [Rule::enum(TaxRegime::class)],
            'sector_id' => ['string', 'max:26'],
            'tax_office_id' => ['string', 'max:26'],
            'pieces_status' => [Rule::enum(PiecesStatus::class)],
            // L'apporteur ne se devine pas par la liste sans le droit de le voir (RG-PTF-06).
            'referrer_id' => app(TenantContext::class)->allows('members.referrers.view') ? ['string', 'max:26'] : ['prohibited'],
            'adhesion_from' => ['date_format:Y-m-d'],
            'adhesion_to' => ['date_format:Y-m-d'],
        ];
    }

    public function sorts(): array
    {
        return ['company_name', 'adhesion_date', 'membership_number', 'created_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [
            AllowedFilter::callback('search', static function (Builder $members, mixed $term): void {
                // Une virgule de la saisie ne fait pas une liste de valeurs (« Kouadio, Fils »).
                $text = mb_strtolower(trim(is_array($term) ? implode(',', $term) : (string) $term));
                $members->where(static fn (Builder $search) => $search
                    ->where('search_text', 'like', '%'.addcslashes($text, '\\%_').'%')
                    ->orWhere('ncc', Ncc::normalize($text)));
            }),
            AllowedFilter::callback('portfolio', static fn (Builder $members, mixed $portfolio) => $portfolio === self::ALL_PORTFOLIOS ? $members : $members->where('portfolio', $portfolio))
                ->default(Portfolio::Cga->value),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('regime'),
            AllowedFilter::exact('sector_id'),
            AllowedFilter::exact('tax_office_id'),
            AllowedFilter::exact('pieces_status'),
            AllowedFilter::exact('referrer_id'),
            AllowedFilter::callback('adhesion_from', static fn (Builder $members, mixed $date) => $members->where('adhesion_date', '>=', $date)),
            AllowedFilter::callback('adhesion_to', static fn (Builder $members, mixed $date) => $members->where('adhesion_date', '<=', $date)),
        ];
    }
}
