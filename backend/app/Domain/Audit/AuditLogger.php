<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Support\Config\SettingsService;
use App\Support\Logging\RequestContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal des modifications (RG-SEC-05, 02-TRD §6.5) : chaque création, modification et suppression
 * avec l'ancienne et la nouvelle valeur de chaque champ, et les événements métier. Les champs cachés
 * du modèle et ceux de logs.redacted_fields sont remplacés par *** : aucun secret en clair.
 */
class AuditLogger
{
    public const REDACTED = '***';

    private const IGNORED_FIELDS = ['created_at', 'updated_at'];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly TenantContext $tenant,
        private readonly RequestContext $request,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  champ => [avant, après]
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, ?Model $subject = null, ?array $changes = null, array $context = []): AuditLog
    {
        $log = new AuditLog;
        $log->organization_id = $this->organizationIdFor($subject);
        // Hors session (Job lancé par un utilisateur), l'auteur est l'utilisateur de l'espace repris.
        $log->user_id = $this->auth->guard()->id() ?? ($this->tenant->has() ? $this->tenant->userId() : null);
        $log->membership_id = $this->tenant->has() ? $this->tenant->membership()->id : null;
        $log->event = $event;
        $log->subject_type = $subject?->getMorphClass();
        $log->subject_id = $subject === null ? null : (string) $subject->getKey();
        $log->changes = $changes === null ? null : $this->redact($changes, $subject);
        $log->context = $context === [] ? null : $context;
        $log->request_id = $this->request->requestId();
        $log->ip = $this->request->ip();
        $log->user_agent = $this->request->userAgent();
        $log->save();

        return $log;
    }

    /**
     * Événement d'un modèle (trait Auditable) : rien n'est écrit si aucun champ n'a changé.
     */
    public function recordModelEvent(string $event, Model $model): void
    {
        $changes = match ($event) {
            'created' => $this->pairs($model->getAttributes(), fn (string $field, mixed $value): array => [null, $value]),
            'updated' => $this->pairs($model->getChanges(), fn (string $field, mixed $value): array => [$model->getRawOriginal($field), $value]),
            default => $this->pairs($model->getRawOriginal(), fn (string $field, mixed $value): array => [$value, null]),
        };

        if ($changes !== []) {
            $this->record($event, $model, $changes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  callable(string, mixed): array{0: mixed, 1: mixed}  $pair
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function pairs(array $attributes, callable $pair): array
    {
        $changes = [];
        foreach ($attributes as $field => $value) {
            if (! in_array($field, self::IGNORED_FIELDS, true)) {
                $changes[$field] = $pair($field, $value);
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function redact(array $changes, ?Model $subject): array
    {
        $sensitive = array_map('strtolower', [...(array) $this->settings->get('logs.redacted_fields'), ...($subject?->getHidden() ?? [])]);

        foreach ($changes as $field => [$before, $after]) {
            if (in_array(strtolower($field), $sensitive, true)) {
                $changes[$field] = [$before === null ? null : self::REDACTED, $after === null ? null : self::REDACTED];
            }
        }

        return $changes;
    }

    private function organizationIdFor(?Model $subject): ?string
    {
        if ($subject instanceof Organization) {
            return $subject->getKey();
        }

        $organizationId = $subject?->getAttribute('organization_id');
        if (is_string($organizationId)) {
            return $organizationId;
        }

        return $this->tenant->has() ? $this->tenant->organizationId() : null;
    }
}
