<?php

namespace App\Domain\Concerns\Http\Requests;

use App\Domain\Concerns\Enums\ConcernCategory;
use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernStatus;
use App\Domain\Concerns\Models\Concern;
use App\Support\Http\ListRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listes des préoccupations, triées par dernière activité :
 * - GET /cga/concerns : onglet « Reçues » (filter[level]=member_to_cga, par défaut ; filtres statut,
 *   catégorie, dossier, « sans réponse depuis n jours », en attente de réponse) ou « Envoyées au CNCGA »
 *   (filter[level]=cga_to_cncga) ;
 * - GET /network/concerns : demandes des CGA (filtres statut, catégorie, CGA) ;
 * - GET /member/concerns : demandes de l'adhérent (filtre statut).
 */
class ListConcernsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return match (true) {
            $this->routeIs('network.*') => Gate::allows('viewNetwork', Concern::class),
            $this->routeIs('member.*') => Gate::allows('writeOwn', Concern::class),
            default => Gate::any(['viewReceived', 'writeToNetwork'], Concern::class),
        };
    }

    /**
     * Dans l'espace du CGA, l'onglet demandé exige son droit.
     *
     * @throws AuthorizationException
     */
    protected function passedValidation(): void
    {
        if (! $this->routeIs('network.*', 'member.*') && ! Gate::allows($this->requestedLevel() === ConcernLevel::CgaToCncga ? 'writeToNetwork' : 'viewReceived', Concern::class)) {
            throw new AuthorizationException;
        }
    }

    protected function filters(): array
    {
        $common = [
            'status' => [Rule::enum(ConcernStatus::class)],
            'category' => [Rule::enum(ConcernCategory::class)],
        ];

        return match (true) {
            $this->routeIs('network.*') => [...$common, 'organization_id' => ['string', 'ulid']],
            $this->routeIs('member.*') => ['status' => $common['status']],
            default => [...$common, 'level' => [Rule::enum(ConcernLevel::class)], 'member_id' => ['string', 'ulid'], 'stale' => ['boolean'], 'awaiting' => ['boolean']],
        };
    }

    public function sorts(): array
    {
        return ['last_activity_at', 'created_at'];
    }

    /** Onglet demandé dans l'espace du CGA, « Reçues » par défaut. */
    public function requestedLevel(): ConcernLevel
    {
        $level = $this->validated('filter.level');

        return is_string($level) ? ConcernLevel::from($level) : ConcernLevel::MemberToCga;
    }
}
