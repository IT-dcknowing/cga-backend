<?php

namespace App\Domain\Notifications\Http\Requests;

use App\Domain\Notifications\Models\MessagingProvider;
use App\Support\Phones\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/platform/messaging/{messagingProvider}/test : message de test (RG-NOT-05) — numéro
 * (SMS, WhatsApp) ou adresse (e-mail) du destinataire ; modèle WhatsApp facultatif.
 */
class TestMessagingProviderRequest extends FormRequest
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
        $provider = $this->route('messagingProvider');
        $email = $provider instanceof MessagingProvider && $provider->channel === 'email';

        return [
            'to' => $email ? ['required', 'email'] : ['required', 'string', app(PhoneNormalizer::class)->rule()],
            'template' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function recipient(): string
    {
        $to = trim((string) $this->validated('to'));

        return str_contains($to, '@') ? $to : app(PhoneNormalizer::class)->e164OrFail($to);
    }

    public function template(): ?string
    {
        $template = $this->validated('template');

        return is_string($template) && $template !== '' ? $template : null;
    }
}
