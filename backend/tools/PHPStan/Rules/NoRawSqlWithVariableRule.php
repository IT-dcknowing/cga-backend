<?php

namespace Tools\PHPStan\Rules;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Injection SQL (02-TRD §6.7, A05) : le SQL brut (whereRaw, orderByRaw, DB::raw, DB::statement…)
 * n'accepte qu'un texte constant écrit dans le code ; toute valeur passe par les paramètres liés.
 * Visés : requêtes, constructeurs Eloquent, relations, connexions, façade DB et modèles Eloquent.
 *
 * @implements Rule<CallLike>
 */
final class NoRawSqlWithVariableRule implements Rule
{
    private const RAW_METHODS = [
        'whereraw', 'orwhereraw', 'havingraw', 'orhavingraw', 'orderbyraw', 'selectraw',
        'groupbyraw', 'fromraw', 'raw', 'statement', 'unprepared',
    ];

    private const DATABASE_TYPES = [
        QueryBuilder::class, EloquentBuilder::class, Relation::class, ConnectionInterface::class, DatabaseManager::class,
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

        if (! $this->targetsDatabase($node, $scope)) {
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

    private function targetsDatabase(MethodCall|StaticCall $node, Scope $scope): bool
    {
        if ($node instanceof MethodCall) {
            return $this->isOneOf($scope->getType($node->var), self::DATABASE_TYPES);
        }

        if (! $node->class instanceof Name) {
            return false;
        }

        $class = $scope->resolveName($node->class);

        return $class === DB::class || $this->isOneOf(new ObjectType($class), [Model::class]);
    }

    /**
     * @param  list<class-string>  $classes
     */
    private function isOneOf(Type $type, array $classes): bool
    {
        foreach ($classes as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }
}
