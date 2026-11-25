<?php

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Data\SessionState;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Permissions\PermissionResolver;
use App\Support\Config\SettingsService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use LogicException;

/**
 * Session HTTP du SPA (02-TRD §6.1, §6.2) : ouverture et fermeture, espace actif, expiration après
 * inactivité (session.timeout_agent_minutes pour les agents, session.timeout_member_minutes pour les
 * adhérents, selon l'espace actif). L'identifiant de session change à la connexion et à chaque
 * changement d'espace (A07).
 */
class AuthSession
{
    public const LAST_ACTIVITY_KEY = 'auth.last_activity_at';

    public const SPACE_KIND_KEY = 'auth.space_kind';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Session $session,
        private readonly SettingsService $settings,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * Ouvre la session de l'utilisateur, sans espace actif.
     */
    public function open(User $user): void
    {
        $this->guard()->login($user);
        $this->session->regenerate();
        $this->session->forget([TenantContext::SESSION_KEY, self::SPACE_KIND_KEY]);
        $this->touch();
    }

    /**
     * Enregistre l'espace actif en session (POST /api/v1/me/space).
     */
    public function enter(Membership $membership): void
    {
        $this->session->regenerate();
        $this->session->put(TenantContext::SESSION_KEY, $membership->id);
        $this->session->put(self::SPACE_KIND_KEY, $membership->role->isMemberRole() ? 'member' : 'agent');
        $this->touch();
    }

    public function close(): void
    {
        $this->guard()->logout();
        $this->session->invalidate();
        $this->session->regenerateToken();
    }

    public function activeMembershipId(): ?string
    {
        $id = $this->session->get(TenantContext::SESSION_KEY);

        return is_string($id) ? $id : null;
    }

    /**
     * Relance le délai d'inactivité.
     */
    public function touch(): void
    {
        $this->session->put(self::LAST_ACTIVITY_KEY, now()->getTimestamp());
    }

    /**
     * La dernière requête date de plus que le délai d'inactivité de l'espace actif (sans espace :
     * délai des agents, le plus court).
     */
    public function isIdle(): bool
    {
        $lastActivity = $this->session->get(self::LAST_ACTIVITY_KEY);
        if (! is_int($lastActivity)) {
            return false;
        }

        $setting = $this->session->get(self::SPACE_KIND_KEY) === 'member'
            ? 'session.timeout_member_minutes'
            : 'session.timeout_agent_minutes';

        return now()->getTimestamp() - $lastActivity > (int) $this->settings->get($setting) * 60;
    }

    /**
     * État renvoyé au SPA : rattachements utilisables, espace actif s'il l'est toujours, droits
     * effectifs de cet espace (verrou → utilisateur → CGA → réseau).
     */
    public function state(User $user): SessionState
    {
        $memberships = $user->memberships()->usable()->with('organization')->oldest()->get();
        $active = $memberships->firstWhere('id', $this->activeMembershipId());

        $permissions = $active === null
            ? []
            : array_keys(array_filter($this->permissions->resolve($active)));

        return new SessionState($user->loadMissing('notificationPreferences'), $memberships, $active?->id, $permissions);
    }

    private function guard(): StatefulGuard
    {
        $guard = $this->auth->guard('web');

        return $guard instanceof StatefulGuard ? $guard : throw new LogicException('La garde « web » doit tenir une session.');
    }
}
