<?php

namespace App\Domain\Trainings\Http\Requests;

use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Trainings\Http\Requests\Concerns\OrganizerTraining;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Inscrire des personnes (F-FOR-02) : POST /{network|cga}/trainings/{training}/registrations (l'organisateur)
 * et POST /cga/network-trainings/{trainingId}/registrations (un CGA, à une formation du réseau) — les
 * rattachements choisis. Chacun est cherché dans le périmètre de l'espace actif : pour le CNCGA, les agents des
 * CGA, jamais un accès adhérent (RG-SEC-01) ; pour un CGA, ses agents et ses adhérents.
 */
class RegisterPeopleRequest extends FormRequest
{
    use OrganizerTraining;

    /**
     * La formation du réseau est cherchée avant toute validation : inconnue, elle répond 404.
     */
    public function authorize(TrainingAccess $access): bool
    {
        if (! $this->routeIs('cga.network-trainings.*')) {
            return $this->managesTraining();
        }

        $access->network((string) $this->route('trainingId'));

        return Gate::allows('browseNetwork', Training::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'membership_ids' => ['required', 'array', 'min:1', 'max:'.(int) $settings->get('pagination.max')],
            'membership_ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<Membership>
     *
     * @throws ValidationException rattachement hors du périmètre
     */
    public function people(TenantContext $context): array
    {
        /** @var list<string> $ids */
        $ids = array_values((array) $this->validated('membership_ids'));
        $people = Membership::query()
            ->whereIn('id', $ids)
            ->where('status', MembershipStatus::Active)
            ->when(
                $context->organizationType() === OrganizationType::Cncga,
                static fn (Builder $query) => $query->whereNull('member_id')->whereHas('organization', static fn (Builder $organization) => $organization->where('type', OrganizationType::Cga)),
                static fn (Builder $query) => $query->where('organization_id', $context->organizationId()),
            )
            ->get();

        if ($people->count() !== count($ids)) {
            throw ValidationException::withMessages(['membership_ids' => __('validation.exists', ['attribute' => 'membership_ids'])]);
        }

        return $people->values()->all();
    }
}
