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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/cga/members : adhérents du centre (F-ADH-01), par portefeuille ; recherche par raison
 * sociale, sigle, NCC, numéros (d'adhésion, client, ancien) ou téléphone ; filtres par statut,
 * régime, secteur, CDI, pièces, apporteur, date d'adhésion.
 */
class ListMembersRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Member::class);
    }

    /**
     * Portefeuille de la liste (CGA par défaut) : celui du Cabinet demande members.cabinet.view.
     */
    public function portfolio(): Portfolio
    {
        return Portfolio::from((string) ($this->validated('filter.portfolio') ?? Portfolio::Cga->value));
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'portfolio' => [Rule::enum(Portfolio::class)],
            'status' => [Rule::enum(MemberStatus::class)],
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
                $text = mb_strtolower(trim((string) $term));
                $members->where(static fn (Builder $search) => $search
                    ->where('search_text', 'like', '%'.addcslashes($text, '\\%_').'%')
                    ->orWhere('ncc', Ncc::normalize($text)));
            }),
            AllowedFilter::exact('portfolio')->default(Portfolio::Cga->value),
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
