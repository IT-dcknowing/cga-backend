<?php

namespace Tests\Tooling\PHPStan;

use PHPStan\Rules\Rule;
use Tools\PHPStan\Rules\NoRawSqlWithVariableRule;

/**
 * Le SQL brut n'accepte qu'un texte constant ; les valeurs passent par les paramètres liés (A05).
 *
 * @extends ProjectRuleTestCase<NoRawSqlWithVariableRule>
 */
final class NoRawSqlWithVariableRuleTest extends ProjectRuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoRawSqlWithVariableRule;
    }

    public function test_refuse_le_sql_brut_construit_avec_une_valeur(): void
    {
        $message = fn (string $method): string => "SQL brut avec une valeur non constante dans {$method}() : utiliser des paramètres liés (02-TRD §6.7, A05).";

        $this->analyse([__DIR__.'/data/raw-sql.php'], [
            [$message('whereRaw'), 17],
            [$message('orderByRaw'), 18],
            [$message('raw'), 19],
            [$message('statement'), 20],
            [$message('whereRaw'), 21],
            [$message('statement'), 22],
        ]);
    }
}
