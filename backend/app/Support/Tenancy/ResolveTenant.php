<?php

namespace App\Support\Tenancy;

use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charge l'espace actif de la session dans TenantContext (02-TRD §6.2). Sans espace choisi, ou si le
 * rattachement n'est plus utilisable (désactivé, organisation résiliée, compte désactivé), les routes
 * métier répondent 409 space_required.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? throw new AuthenticationException;

        $membershipId = $request->session()->get(TenantContext::SESSION_KEY);
        $membership = is_string($membershipId)
            ? Membership::query()
                ->with('organization')
                ->whereKey($membershipId)
                ->where('user_id', $user->getAuthIdentifier())
                ->usable()
                ->first()
            : null;

        if (! $this->usable($membership)) {
            $request->session()->forget(TenantContext::SESSION_KEY);

            throw new BusinessRuleException('space_required');
        }

        $this->context->set($membership);

        return $next($request);
    }

    /**
     * @phpstan-assert-if-true Membership $membership
     */
    private function usable(?Membership $membership): bool
    {
        return $membership !== null
            && $membership->user()->where('status', UserStatus::Active)->exists();
    }
}
