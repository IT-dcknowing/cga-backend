<?php

namespace App\Support\Logging;

use App\Domain\Audit\Enums\ActivityResult;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Journal d'activité (RG-SEC-05, 02-TRD §6.5) : une ligne par requête, Job, tâche planifiée, commande
 * ou envoi. Les champs sensibles (logs.redacted_fields) sont remplacés par ***. L'écriture ne fait
 * jamais échouer l'action : en cas d'échec, la ligne part dans le journal de secours.
 */
class ActivityLogger
{
    public function __construct(
        private readonly Repository $config,
        private readonly SettingsService $settings,
        private readonly RequestContext $request,
        private readonly AuthFactory $auth,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  colonnes de activity_logs (actor_type, route, params, http_status…)
     */
    public function record(string $action, ActivityResult $result, array $entry = []): void
    {
        $row = [];

        try {
            $row = $this->row($action, $result, $entry);
            DB::table('activity_logs')->insert($row);
        } catch (Throwable $exception) {
            $this->fallback($row !== [] ? $row : ['action' => $action, 'result' => $result->value], $exception);
        }
    }

    /**
     * Remplace les valeurs sensibles par ***, à toute profondeur, et tronque les textes trop longs.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        $sensitive = $this->sensitiveFields();
        $maxLength = $this->config->integer('activity.string_max_length');

        $walk = function (array $node) use (&$walk, $sensitive, $maxLength): array {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                    $node[$key] = '***';
                } elseif (is_array($value)) {
                    $node[$key] = $walk($value);
                } elseif (is_string($value) && mb_strlen($value) > $maxLength) {
                    $node[$key] = mb_substr($value, 0, $maxLength).'…';
                }
            }

            return $node;
        };

        return $walk($values);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function row(string $action, ActivityResult $result, array $entry): array
    {
        $userId = $entry['user_id'] ?? $this->auth->guard()->id();
        $membership = $this->tenant->has() ? $this->tenant->membership() : null;

        $row = [
            'created_at' => now(),
            'request_id' => $this->request->requestId(),
            'actor_type' => $userId === null ? 'public' : 'user',
            'actor_label' => null,
            'user_id' => $userId,
            'membership_id' => $membership?->id,
            'role' => $membership?->role->value,
            'organization_id' => $membership?->organization_id,
            'method' => null,
            'route' => null,
            'subject_type' => null,
            'subject_id' => null,
            'params' => null,
            'http_status' => null,
            'duration_ms' => null,
            'error_code' => null,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            ...$entry,
            'action' => mb_substr($action, 0, 100),
            'result' => $result->value,
        ];

        $row['params'] = is_array($row['params']) ? $this->encodeParams($row['params']) : null;

        return $row;
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function encodeParams(array $params): ?string
    {
        if ($params === []) {
            return null;
        }

        $json = (string) json_encode($this->redact($params), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen($json) <= $this->config->integer('activity.params_max_bytes')
            ? $json
            : (string) json_encode(['_truncated' => true, 'keys' => array_keys($params)]);
    }

    /**
     * @return list<string>
     */
    private function sensitiveFields(): array
    {
        try {
            $fields = (array) $this->settings->get('logs.redacted_fields');
        } catch (Throwable) {
            $fields = (array) $this->settings->default('logs.redacted_fields');
        }

        return array_values(array_map(static fn (mixed $field): string => strtolower((string) $field), $fields));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fallback(array $row, Throwable $exception): void
    {
        try {
            Log::channel($this->config->string('activity.fallback_channel'))->error('activity_log_write_failed', [
                'entry' => $row,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            // Dernier recours : le journal de secours lui-même est indisponible ; l'action continue.
            error_log('activity_log_write_failed: '.$exception->getMessage());
        }
    }
}
