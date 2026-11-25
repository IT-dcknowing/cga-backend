<?php

namespace App\Domain\Members\Http\Requests;

use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Support\Phones\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cga/members : inscription d'un adhérent, enregistrée comme brouillon (F-ADH-02,
 * RG-ADH-01), dans le portefeuille choisi.
 */
class StoreMemberRequest extends MemberRequest
{
    /**
     * Droit d'inscrire ; celui de voir le portefeuille demandé est contrôlé une fois la saisie validée.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', [Member::class, Portfolio::Cga]) || Gate::allows('create', [Member::class, Portfolio::Cabinet]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PhoneNormalizer $phones, TenantContext $context): array
    {
        return [
            'portfolio' => ['required', Rule::enum(Portfolio::class)],
            ...parent::rules($phones, $context),
            'transfer_id' => ['nullable', 'string', 'max:26'],
        ];
    }

    /**
     * Réimport depuis un autre CGA dont l'inscription reprend l'identité (RG-ADH-15).
     */
    public function transferId(): ?string
    {
        $transfer = $this->validated('transfer_id');

        return is_string($transfer) ? $transfer : null;
    }

    public function portfolio(): Portfolio
    {
        return Portfolio::from((string) $this->validated('portfolio'));
    }

    protected function fixedPortfolio(): ?Portfolio
    {
        return null;
    }
}
