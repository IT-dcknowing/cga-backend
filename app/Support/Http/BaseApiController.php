<?php

namespace App\Support\Http;

use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Logging\LogActivity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Base des contrôleurs de l'API (02-TRD §4.4, règle stricte) : tout le corps d'une action passe par
 * handle(), qui la nomme dans le journal d'activité, signale toute erreur inattendue (journal
 * d'erreurs, Sentry) et la traduit en réponse normalisée. Jamais d'exception avalée.
 */
abstract class BaseApiController extends Controller
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  string  $action  nom stable de l'action dans le journal (members.radiate, documents.download…)
     * @return T|JsonResponse
     */
    protected function handle(callable $callback, string $action): mixed
    {
        $request = request();
        $request->attributes->set(LogActivity::ACTION, $action);

        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return app(ApiErrorMapper::class)->render($exception, $request);
        }
    }

    /**
     * Objet visé par l'action dans le journal d'activité, quand ce n'est pas un paramètre de la route.
     * Noté sur la requête d'origine : une FormRequest n'en est qu'une copie.
     */
    protected function logSubject(Model $subject): void
    {
        request()->attributes->set(LogActivity::SUBJECT, $subject);
    }

    /**
     * Utilisateur de l'action dans le journal d'activité, quand la session ne le dira plus (déconnexion).
     */
    protected function logUser(User $user): void
    {
        request()->attributes->set(LogActivity::USER_ID, $user->id);
    }

    /**
     * Acteur sans compte de l'action dans le journal d'activité (page publique de vérification).
     */
    protected function logActor(string $label): void
    {
        request()->attributes->set(LogActivity::ACTOR_LABEL, $label);
    }

    /**
     * Utilisateur connecté (routes protégées par le middleware « auth »).
     *
     * @throws AuthenticationException sans session
     */
    protected function currentUser(): User
    {
        $user = request()->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
