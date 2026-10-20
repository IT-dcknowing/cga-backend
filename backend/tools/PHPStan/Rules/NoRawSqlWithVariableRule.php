<?php

namespace Tools\PHPStan\Rules;

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Injection SQL (02-TRD §6.7, A05) : le SQL brut (whereRaw, orderByRaw, DB::raw…) n'accepte qu'un
 * texte constant écrit dans le code ; toute valeur passe par les paramètres liés.
 *
 * @implements Rule<CallLike>
 */
final class NoRawSqlWithVariableRule implements Rule
{
    private const RAW_METHODS = [
        'whereraw', 'orwhereraw', 'havingraw', 'orhavingraw', 'orderbyraw', 'selectraw',
        'groupbyraw', 'fromraw', 'raw', 'statement', 'unprepared',
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier || ! in_array($node->name->toLowerString(), self::RAW_METHODS, true)) {
            return [];
        }

        if ($node instanceof StaticCall && ! ($node->class instanceof Name && $scope->resolveName($node->class) === DB::class)) {
            return [];
        }

        $sql = $node->getArgs()[0] ?? null;
        if ($sql === null || $scope->getType($sql->value)->getConstantStrings() !== []) {
            return [];
        }

        return [
            RuleErrorBuilder::message("SQL brut avec une valeur non constante dans {$node->name->toString()}() : utiliser des paramètres liés (02-TRD §6.7, A05).")
                ->identifier('cga.rawSqlWithVariable')
                ->build(),
        ];
    }
}
