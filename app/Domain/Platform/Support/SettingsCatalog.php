<?php

namespace App\Domain\Platform\Support;

use App\Support\Config\SettingsService;

/**
 * Paramètres modifiables à l'écran (02-TRD §7.5, 03-APPFLOW §3.2 et §6) : au CNCGA, les clés de niveau
 * réseau (« Paramètres réseau », « Règles du rapport », RG-RPT-08) ; à l'administrateur de la plateforme,
 * les clés de niveau plateforme et réseau. Une clé interne n'est jamais exposée.
 */
final class SettingsCatalog
{
    public const NETWORK = 'network';

    public const PLATFORM = 'platform';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Clés de l'espace, par ordre alphabétique.
     *
     * @return array<string, array{type: string, level: string|list<string>, default: mixed, rules?: array<string, list<string>>, permission?: string|null, internal?: bool}>
     */
    public function visible(string $space): array
    {
        $levels = $space === self::PLATFORM ? ['platform', 'network'] : ['network'];
        $keys = array_filter(
            $this->settings->definitions(),
            static fn (array $definition): bool => ! ($definition['internal'] ?? false)
                && array_intersect($levels, (array) $definition['level']) !== [],
        );
        ksort($keys);

        return $keys;
    }

    public function has(string $space, string $key): bool
    {
        return array_key_exists($key, $this->visible($space));
    }

    /**
     * Groupes de l'espace : premier segment des clés (« report », « dues »…).
     *
     * @return list<string>
     */
    public function groups(string $space): array
    {
        return array_values(array_unique(array_map(static fn (string $key): string => explode('.', $key)[0], array_keys($this->visible($space)))));
    }

    /**
     * Droit requis pour modifier la clé dans l'espace : celui du registre au CNCGA ; l'administrateur
     * modifie toutes les clés qu'il voit avec platform.settings.manage.
     */
    public function permission(string $space, string $key): ?string
    {
        return $space === self::PLATFORM ? 'platform.settings.manage' : ($this->visible($space)[$key]['permission'] ?? null);
    }
}
