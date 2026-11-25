<?php

namespace App\Domain\Notifications\Http\Requests;

use App\Domain\Notifications\Models\MessagingProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/platform/messaging et PUT …/{messagingProvider} : compte d'envoi (RG-NOT-05, F-ADM-06) —
 * canal, pilote compatible avec le canal, libellé, paramètres et identifiants propres au pilote
 * (config/messaging.php), contrôlés une fois le pilote validé. En modification du même pilote, un
 * identifiant non saisi garde sa valeur.
 */
class MessagingProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', MessagingProvider::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['sms', 'whatsapp', 'email'])],
            'driver' => ['required', Rule::in(array_keys((array) config('messaging.drivers')))],
            'label' => ['required', 'string', 'max:100'],
            'settings' => ['present', 'array'],
            'secrets' => ['present', 'array'],
        ];
    }

    /**
     * Règles du pilote choisi, appliquées aux données déjà contrôlées par rules().
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            $definition = (array) config('messaging.drivers.'.$data['driver']);
            if (! in_array($data['channel'], $definition['channels'], true)) {
                $validator->errors()->add('driver', __('notifications.driver_channel'));

                return;
            }
            $current = $this->route('messagingProvider');
            $updating = $current instanceof MessagingProvider && $current->driver === $data['driver'];
            $rules = [
                'settings' => ['array:'.implode(',', array_filter(array_keys($definition['settings']), static fn (string $key): bool => ! str_contains($key, '.')))],
                'secrets' => ['array:'.implode(',', array_keys($definition['secrets']))],
            ];
            foreach ($definition['settings'] as $field => $fieldRules) {
                $rules["settings.{$field}"] = $fieldRules;
            }
            foreach ($definition['secrets'] as $field => $fieldRules) {
                $rules["secrets.{$field}"] = $updating ? ['nullable', ...array_values(array_diff($fieldRules, ['required']))] : $fieldRules;
            }
            foreach (ValidatorFactory::make($data, $rules)->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        }];
    }

    public function channel(): string
    {
        return (string) $this->validated('channel');
    }

    public function driver(): string
    {
        return (string) $this->validated('driver');
    }

    public function label(): string
    {
        return trim((string) $this->validated('label'));
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return (array) $this->validated('settings');
    }

    /**
     * Identifiants saisis (les vides sont écartés : ils gardent leur valeur).
     *
     * @return array<string, string>
     */
    public function secrets(): array
    {
        return array_map('strval', array_filter((array) $this->validated('secrets'), static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }
}
