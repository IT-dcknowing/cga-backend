<?php

namespace App\Domain\Users\Permissions;

use App\Domain\Users\Enums\Role;
use App\Support\Http\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Config\Repository;

/**
 * Catalogue des permissions déclaré dans config/permissions.php (02-TRD §6.2) : code, verrou et
 * matrice par défaut des rôles (01-PRD §3.3).
 */
class PermissionCatalog
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<string, array{locked: bool, roles: list<string>}>
     */
    public function all(): array
    {
        return $this->config->array('permissions.permissions');
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->all());
    }

    public function isLocked(string $code): bool
    {
        return $this->entry($code)['locked'];
    }

    /**
     * Valeur de la matrice par défaut (et valeur permanente d'une permission verrouillée).
     */
    public function grantsByDefault(Role $role, string $code): bool
    {
        return in_array($role->value, $this->entry($code)['roles'], true);
    }

    /**
     * Module du code (« members.radiation.cancel » → « members »), pour regrouper les droits à l'écran.
     */
    public function module(string $code): string
    {
        $this->entry($code);

        return explode('.', $code, 2)[0];
    }

    /**
     * Module de l'interface d'un rôle où le droit est rangé (config permissions.modules, F-ADM-05).
     *
     * @throws UnknownPermissionException code ou module inconnu
     */
    public function screenModule(string $code): string
    {
        $prefix = $this->module($code);
        foreach ($this->screenModules() as $module => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $module;
            }
        }

        throw new UnknownPermissionException($code);
    }

    /**
     * Modules de l'interface d'un rôle, dans l'ordre d'affichage : module => débuts de code.
     *
     * @return array<string, list<string>>
     */
    public function screenModules(): array
    {
        return $this->config->array('permissions.modules');
    }

    public function label(string $code): string
    {
        $this->entry($code);

        return __("permissions.{$code}");
    }

    /**
     * Un droit ne s'écrit (rôle ou utilisateur) que s'il existe et n'est pas verrouillé (RG-ROLE-04).
     *
     * @throws BusinessRuleException
     */
    public function assertWritable(string $code): void
    {
        if (! $this->has($code)) {
            throw new BusinessRuleException('unknown_permission', status: 422);
        }

        if ($this->isLocked($code)) {
            throw new BusinessRuleException('locked_permission');
        }
    }

    /**
     * @return array{locked: bool, roles: list<string>}
     */
    private function entry(string $code): array
    {
        return $this->all()[$code] ?? throw new UnknownPermissionException($code);
    }
}
