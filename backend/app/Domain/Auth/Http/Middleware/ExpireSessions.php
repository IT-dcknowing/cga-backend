<?php

namespace App\Domain\Auth\Http\Middleware;

use App\Domain\Auth\Support\AuthSession;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme la session après l'inactivité permise par l'espace actif (30 min agents, 60 min adhérents,
 * 02-TRD §6.1) ou dès que le compte n'est plus actif ; sinon relance le délai. La requête continue
 * sans utilisateur : une route protégée répond alors 401, la connexion reste possible.
 */
class ExpireSessions
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly AuthSession $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('web');

        if ($request->hasSession() && $guard->check()) {
            $user = $guard->user();

            if ($this->session->isIdle() || ! $user instanceof User || $user->status !== UserStatus::Active) {
                $this->session->close();
            } else {
                $this->session->touch();
            }
        }

        return $next($request);
    }
}
