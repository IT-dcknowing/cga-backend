<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Members\Models\Member;
use App\Domain\Payments\Models\Invoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/invoices : « Émettre une facture » (F-PAY-05) — dossier du centre (cherché dans le
 * centre de l'espace actif) et sommes dues à facturer ; numéro, lignes et montants sont calculés par le
 * serveur ; invoices.issue.
 */
class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Invoice::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContext $context): array
    {
        return [
            'member_id' => ['required', 'string', 'size:26', Rule::exists('members', 'id')->where('organization_id', $context->organizationId())->whereNull('deleted_at')],
            'charge_ids' => ['required', 'array', 'min:1', 'max:100'],
            'charge_ids.*' => ['required', 'string', 'size:26', 'distinct'],
        ];
    }

    public function member(): Member
    {
        return Member::query()->findOrFail((string) $this->validated('member_id'));
    }

    /**
     * @return list<string>
     */
    public function chargeIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('charge_ids')));
    }
}
