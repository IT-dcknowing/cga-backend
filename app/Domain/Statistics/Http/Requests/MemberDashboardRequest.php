<?php

namespace App\Domain\Statistics\Http\Requests;

use App\Domain\Statistics\Models\StatOrgDaily;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /member/dashboard : tableau de bord de l'adhérent (F-DSH-03), sans période ni paramètre.
 */
class MemberDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewMemberDashboard', StatOrgDaily::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
