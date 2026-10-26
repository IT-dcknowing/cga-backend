<?php

namespace App\Support\Config;

use App\Domain\Platform\Models\Setting;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Paramètres déclarés dans le registre config/cga.php (02-TRD §7.5) : valeur du CGA, sinon valeur du
 * réseau (ou de la plateforme), sinon valeur par défaut du registre. Une écriture est validée selon
 * le registre, journalisée avec l'avant / après (Setting est Auditable) et incrémente settings.version.
 */
class SettingsService
{
    private const VERSION_KEY = 'settings.version';

    /**
     * @var array<string, array{found: bool, value: mixed}>
     */
    private array $memo = [];

    public function __construct(
        private readonly Repository $config,
        private readonly ValidatorFactory $validator,
    ) {}

    /**
     * @throws InvalidArgumentException si la clé n'est pas déclarée dans le registre
     * @throws QueryException si la base ne répond pas
     */
    public function get(string $key, ?string $organizationId = null): mixed
    {
        $definition = $this->definition($key);

        if ($organizationId !== null && $this->allowsLevel($definition, 'cga')) {
            $own = $this->stored($key, $organizationId);
            if ($own['found']) {
                return $own['value'];
            }
        }

        $network = $this->stored($key, null);

        return $network['found'] ? $network['value'] : $definition['default'];
    }

    /**
     * Valeur par défaut du registre, sans lecture de la base (point de santé, repli).
     */
    public function default(string $key): mixed
    {
        return $this->definition($key)['default'];
    }

    /**
     * Valeur du réseau ou de la plateforme (sans organisation), ou valeur propre d'un CGA.
     *
     * @throws BusinessRuleException si la clé est interne ou si le niveau ne l'admet pas
     * @throws ValidationException si la valeur ne respecte pas le registre
     */
    public function set(string $key, mixed $value, ?string $organizationId = null, ?string $actorId = null): void
    {
        $definition = $this->editableDefinition($key, $organizationId);

        $this->validator->make(['value' => $value], $this->rules($definition), [], ['value' => $key])->validate();

        DB::transaction(function () use ($key, $value, $organizationId, $actorId): void {
            $setting = $this->row($key, $organizationId) ?? new Setting;
            $setting->organization_id = $organizationId;
            $setting->key = $key;
            $setting->value = $value;
            $setting->updated_by = $actorId;
            $setting->save();

            $this->incrementVersion();
        });

        $this->memo = [];
    }

    /**
     * Revient à la valeur héritée (réseau ou registre) en supprimant la valeur enregistrée.
     */
    public function reset(string $key, ?string $organizationId = null): void
    {
        $this->editableDefinition($key, $organizationId);

        DB::transaction(function () use ($key, $organizationId): void {
            $this->row($key, $organizationId)?->delete();
            $this->incrementVersion();
        });

        $this->memo = [];
    }

    /**
     * Numéro de version des paramètres : il change à chaque modification (02-TRD §7.5).
     */
    public function version(): int
    {
        return (int) $this->get(self::VERSION_KEY);
    }

    /**
     * Valeurs utilisées par un calcul conservé (rapport, score bancaire), pour les enregistrer avec lui.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function snapshot(array $keys, ?string $organizationId = null): array
    {
        $values = ['settings.version' => $this->version()];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $organizationId);
        }

        return $values;
    }

    /**
     * Toutes les clés déclarées, à plat (« files.disk_alert_percent » => définition).
     *
     * @return array<string, array{type: string, level: string|list<string>, default: mixed, rules?: array<string, list<string>>, permission?: string|null, internal?: bool}>
     */
    public function definitions(): array
    {
        return $this->flatten((array) $this->config->get('cga.settings', []));
    }

    /**
     * @return array{type: string, level: string|list<string>, default: mixed, rules?: array<string, list<string>>, permission?: string|null, internal?: bool}
     */
    public function definition(string $key): array
    {
        $definition = $this->config->get('cga.settings.'.$key);

        if (! is_array($definition) || ! $this->isDefinition($definition)) {
            throw new InvalidArgumentException("Paramètre non déclaré dans le registre config/cga.php : {$key}.");
        }

        return $definition;
    }

    /**
     * Une définition réunit type, niveau et valeur par défaut ; un groupe peut contenir une clé nommée
     * « default » (pagination.default) sans être une définition.
     *
     * @param  array<mixed>  $node
     */
    private function isDefinition(array $node): bool
    {
        return is_string($node['type'] ?? null) && array_key_exists('level', $node) && array_key_exists('default', $node);
    }

    /**
     * @return array{type: string, level: string|list<string>, default: mixed, rules?: array<string, list<string>>, permission?: string|null, internal?: bool}
     */
    private function editableDefinition(string $key, ?string $organizationId): array
    {
        $definition = $this->definition($key);
        $level = $organizationId === null ? ['platform', 'network'] : ['cga'];

        $allowed = array_filter($level, fn (string $candidate): bool => $this->allowsLevel($definition, $candidate)) !== [];
        if (($definition['internal'] ?? false) || ! $allowed) {
            throw new BusinessRuleException('setting_not_editable', details: ['key' => $key]);
        }

        return $definition;
    }

    /**
     * @param  array{level: string|list<string>}  $definition
     */
    private function allowsLevel(array $definition, string $level): bool
    {
        return in_array($level, (array) $definition['level'], true);
    }

    /**
     * Règles du registre appliquées à ['value' => …] : '' vise la valeur, 'champ' un de ses champs.
     *
     * @param  array{rules?: array<string, list<string>>}  $definition
     * @return array<string, list<string>>
     */
    private function rules(array $definition): array
    {
        $rules = ['value' => ['present']];
        foreach ($definition['rules'] ?? [] as $path => $pathRules) {
            $rules[$path === '' ? 'value' : "value.{$path}"] = $path === '' ? ['present', ...$pathRules] : $pathRules;
        }

        return $rules;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function stored(string $key, ?string $organizationId): array
    {
        $memoKey = ($organizationId ?? '').'|'.$key;

        if (! array_key_exists($memoKey, $this->memo)) {
            $row = $this->row($key, $organizationId);
            $this->memo[$memoKey] = ['found' => $row !== null, 'value' => $row?->value];
        }

        return $this->memo[$memoKey];
    }

    private function row(string $key, ?string $organizationId): ?Setting
    {
        return Setting::query()
            ->where('key', $key)
            ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
            ->first();
    }

    /**
     * Incrément atomique en base : deux modifications simultanées donnent deux versions distinctes.
     */
    private function incrementVersion(): void
    {
        DB::table('settings')->insertOrIgnore([
            'organization_id' => null, 'key' => self::VERSION_KEY, 'value' => '0', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('settings')
            ->whereNull('organization_id')
            ->where('key', self::VERSION_KEY)
            ->update(['value' => DB::raw('to_jsonb((value #>> \'{}\')::bigint + 1)'), 'updated_at' => now()]);

        $this->memo = [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, array{type: string, level: string|list<string>, default: mixed}>
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $definitions = [];
        foreach ($node as $name => $child) {
            if (! is_array($child)) {
                continue;
            }

            if ($this->isDefinition($child)) {
                /** @var array{type: string, level: string|list<string>, default: mixed} $child */
                $definitions[$prefix.$name] = $child;
            } else {
                $definitions += $this->flatten($child, "{$prefix}{$name}.");
            }
        }

        return $definitions;
    }
}
