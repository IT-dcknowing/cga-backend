<?php

namespace App\Domain\Organizations\Http\Requests;

use Carbon\CarbonImmutable;

/**
 * POST /api/v1/network/organizations/{organization}/withdraw-agreement : perte d'agrément marquée par
 * la Présidente (F-ORG-07), à une date passée ou du jour, avec motif.
 */
class WithdrawAgreementRequest extends StatusReasonRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'withdrawn_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ...parent::rules(),
        ];
    }

    public function withdrawnAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('withdrawn_at'))->startOfDay();
    }
}
