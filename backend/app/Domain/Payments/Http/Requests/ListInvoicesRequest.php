<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Enums\InvoiceStatus;
use App\Domain\Payments\Enums\InvoiceType;
use App\Domain\Payments\Models\Invoice;
use App\Support\Http\ListRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/cga/invoices : factures et avoirs du centre (`/cga/factures`, onglet « Honoraires et
 * paiements » du dossier), les plus récents d'abord, filtrés par statut FNE, nature et dossier.
 */
class ListInvoicesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Invoice::class);
    }

    protected function filters(): array
    {
        return [
            'status' => [Rule::enum(InvoiceStatus::class)],
            'type' => [Rule::enum(InvoiceType::class)],
            'member_id' => ['string', 'max:26'],
        ];
    }

    public function sorts(): array
    {
        return ['issue_date', 'number', 'amount_incl_tax', 'created_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [AllowedFilter::exact('status'), AllowedFilter::exact('type'), AllowedFilter::exact('member_id')];
    }
}
