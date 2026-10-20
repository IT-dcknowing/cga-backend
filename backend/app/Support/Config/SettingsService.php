<?php

namespace App\Support\Config;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Lit les paramètres déclarés dans le registre config/cga.php (02-TRD §7.5). Pour l'instant, seule
 * la valeur par défaut du registre est connue : les valeurs enregistrées (table « settings », réseau
 * puis CGA) arrivent aux étapes 0.6 et 0.7.
 */
class SettingsService
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @throws InvalidArgumentException si la clé n'est pas déclarée dans le registre
     */
    public function get(string $key): mixed
    {
        return $this->definition($key)['default'];
    }

    /**
     * Toutes les clés déclarées, à plat (« files.disk_alert_percent » => définition).
     *
     * @return array<string, array{type: string, level: string, default: mixed}>
     */
    public function definitions(): array
    {
        return $this->flatten((array) $this->config->get('cga.settings', []));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, array{type: string, level: string, default: mixed}>
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $definitions = [];
        foreach ($node as $name => $child) {
            if (! is_array($child)) {
                continue;
            }

            if (array_key_exists('default', $child)) {
                /** @var array{type: string, level: string, default: mixed} $child */
                $definitions[$prefix.$name] = $child;
            } else {
                $definitions += $this->flatten($child, "{$prefix}{$name}.");
            }
        }

        return $definitions;
    }

    /**
     * @return array{type: string, level: string, default: mixed}
     */
    private function definition(string $key): array
    {
        $definition = $this->config->get('cga.settings.'.$key);

        if (! is_array($definition) || ! array_key_exists('default', $definition)) {
            throw new InvalidArgumentException("Paramètre non déclaré dans le registre config/cga.php : {$key}.");
        }

        return $definition;
    }
}
