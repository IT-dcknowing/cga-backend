<?php

namespace App\Domain\Notifications\Http\Resources;

use App\Domain\Notifications\Models\MessagingProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compte d'envoi (écran « Envois », RG-NOT-05) : paramètres en clair, identifiants jamais réaffichés —
 * seulement leurs derniers caractères —, état, dernier test.
 *
 * @property MessagingProvider $resource
 */
class MessagingProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $provider = $this->resource;
        $visible = (int) config('messaging.secret_visible_chars');

        return [
            'id' => $provider->id,
            'channel' => $provider->channel,
            'driver' => $provider->driver,
            'label' => $provider->label,
            'settings' => (object) $provider->settings,
            'secrets' => (object) array_map(
                static fn (string $value): ?string => $value !== '' ? '••••'.mb_substr($value, -$visible) : null,
                $provider->secrets,
            ),
            'is_active' => $provider->is_active,
            'last_test_at' => $provider->last_test_at?->toIso8601ZuluString(),
            'last_test_result' => $provider->last_test_result,
            'updated_at' => $provider->updated_at?->toIso8601ZuluString(),
        ];
    }
}
