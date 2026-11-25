<?php

namespace Tests\Tooling\PHPStan;

use PHPStan\Rules\Rule;
use Tools\PHPStan\Rules\NoUnvalidatedRequestDataRule;

/**
 * Seules les données validées de la requête sont lues (02-TRD §6.7, règle 2).
 *
 * @extends ProjectRuleTestCase<NoUnvalidatedRequestDataRule>
 */
final class NoUnvalidatedRequestDataRuleTest extends ProjectRuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoUnvalidatedRequestDataRule;
    }

    public function test_refuse_la_lecture_directe_des_donnees_de_la_requete(): void
    {
        $message = fn (string $method): string => "Donnée non validée : \$request->{$method}() est interdit, utiliser \$request->validated() ou \$request->safe() (02-TRD §6.7).";

        $this->analyse([__DIR__.'/data/unvalidated-request-data.php'], [
            [$message('all'), 12],
            [$message('input'), 13],
            [$message('only'), 14],
            [$message('all'), 15],
            [$message('integer'), 16],
        ]);
    }
}
