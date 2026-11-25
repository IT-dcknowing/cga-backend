<?php

namespace App\Domain\Closing\Http\Requests;

use App\Domain\Closing\Enums\ClosingStep;
use App\Domain\Closing\Models\ClosingCampaign;
use App\Support\Config\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Actions groupées de /cga/cloture (F-OBL-08) sur une sélection de dossiers de la campagne, au plus une
 * page de la liste (pagination.max) :
 * - POST …/{closingCampaign}/bulk/steps/{step} : « Marquer l'étape faite », même date pour tous ; le
 *   dépôt légal se marque dossier par dossier (référence et accusé propres) ;
 * - POST …/{closingCampaign}/bulk/assign : « Affecter à un agent » (aucun : null).
 * Chaque dossier est cherché dans la campagne, elle-même cherchée dans l'espace actif.
 */
class ClosingSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', ClosingCampaign::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        $rules = [
            'item_ids' => ['required', 'array', 'min:1', 'max:'.(int) $settings->get('pagination.max')],
            'item_ids.*' => ['required', 'string', 'size:26', 'distinct', Rule::exists('closing_campaign_items', 'id')->where('campaign_id', $this->campaign()->id)],
        ];
        if ($this->route('step') !== null) {
            $rules['done_on'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
        } else {
            $rules['assigned_to'] = ['present', 'nullable', 'string', 'ulid'];
        }

        return $rules;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->step() === ClosingStep::Filing) {
                $validator->errors()->add('step', __('closing.filing_not_bulk'));
            }
        }];
    }

    /**
     * @return list<string>
     */
    public function itemIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('item_ids')));
    }

    public function step(): ?ClosingStep
    {
        $step = $this->route('step');

        return $step instanceof ClosingStep ? $step : ClosingStep::tryFrom((string) $step);
    }

    public function doneOn(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('done_on'));
    }

    public function assignedTo(): ?string
    {
        $agent = $this->validated('assigned_to');

        return is_string($agent) ? $agent : null;
    }

    /**
     * @throws AuthorizationException route sans campagne
     */
    public function campaign(): ClosingCampaign
    {
        $campaign = $this->route('closingCampaign');

        return $campaign instanceof ClosingCampaign ? $campaign : throw new AuthorizationException;
    }
}
