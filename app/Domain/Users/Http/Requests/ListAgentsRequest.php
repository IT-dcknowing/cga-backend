<?php

namespace App\Domain\Users\Http\Requests;

use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\AgentSpace;
use App\Support\Http\ListRequest;
use App\Support\Http\Query\SearchFilter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * GET /api/v1/{cga|network}/users : agents de l'organisation, filtrés par nom, téléphone ou e-mail,
 * par rôle et par état (actif, invité, désactivé).
 */
class ListAgentsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', [User::class, AgentSpace::of($this)]);
    }

    protected function filters(): array
    {
        return [
            'search' => ['string', 'max:100'],
            'role' => [Rule::enum(Role::class)],
            'status' => [Rule::in([MembershipStatus::Active->value, MembershipStatus::Invited->value, MembershipStatus::Disabled->value])],
        ];
    }

    public function sorts(): array
    {
        return ['last_name', 'first_name', 'created_at'];
    }

    /**
     * @return list<AllowedFilter>
     */
    public function queryFilters(TenantContext $context): array
    {
        $organizationId = $context->organizationId();
        $inOrganization = static fn (Builder $memberships): Builder => $memberships->where('organization_id', $organizationId)->whereNull('member_id');

        return [
            AllowedFilter::custom('search', new SearchFilter(['first_name', 'last_name', 'phone', 'email'])),
            AllowedFilter::callback('role', static fn (Builder $users, mixed $role) => $users->whereHas('memberships', static fn (Builder $m) => $inOrganization($m)->where('role', $role)->where('status', '!=', MembershipStatus::Disabled))),
            AllowedFilter::callback('status', static function (Builder $users, mixed $status) use ($inOrganization): void {
                $active = [MembershipStatus::Active, MembershipStatus::ReadOnly];
                match ($status) {
                    MembershipStatus::Active->value => $users->whereHas('memberships', static fn (Builder $m) => $inOrganization($m)->whereIn('status', $active)),
                    MembershipStatus::Invited->value => $users
                        ->whereHas('memberships', static fn (Builder $m) => $inOrganization($m)->where('status', MembershipStatus::Invited))
                        ->whereDoesntHave('memberships', static fn (Builder $m) => $inOrganization($m)->whereIn('status', $active)),
                    default => $users->whereDoesntHave('memberships', static fn (Builder $m) => $inOrganization($m)->where('status', '!=', MembershipStatus::Disabled)),
                };
            }),
        ];
    }
}
