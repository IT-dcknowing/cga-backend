<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Enums\PaymentSource;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\MemberPayment;
use App\Support\Http\ListRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/cga/payments : paiements du centre, les plus récents d'abord, filtrés par état (les
 * déclarations « à confirmer »), origine et dossier.
 */
class ListPaymentsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewCentre', MemberPayment::class);
    }

    protected function filters(): array
    {
        return [
            'status' => [Rule::enum(PaymentStatus::class)],
            'source' => [Rule::enum(PaymentSource::class)],
            'member_id' => ['string', 'max:26'],
        ];
    }

    public function sorts(): array
    {
        return ['paid_on', 'created_at', 'amount'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(): array
    {
        return [AllowedFilter::exact('status'), AllowedFilter::exact('source'), AllowedFilter::exact('member_id')];
    }
}
