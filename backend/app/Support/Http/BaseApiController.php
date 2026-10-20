<?php

namespace App\Support\Http;

use App\Http\Controllers\Controller;
use App\Support\Logging\LogActivity;
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
}
