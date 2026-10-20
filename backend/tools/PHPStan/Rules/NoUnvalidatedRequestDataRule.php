<?php

namespace Tools\PHPStan\Rules;

use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * « Ne jamais faire confiance aux données qui viennent du frontend » (02-TRD §6.7, règle 2) : seules
 * les données validées sont lues, par $request->validated() ou $request->safe(). Les accès directs
 * aux données de la requête ($request->all(), input(), only()…) sont refusés.
 *
 * @implements Rule<MethodCall>
 */
final class NoUnvalidatedRequestDataRule implements Rule
{
    private const FORBIDDEN_METHODS = [
        'all', 'input', 'only', 'except', 'post', 'query', 'json', 'get', 'collect',
        'string', 'str', 'integer', 'float', 'boolean', 'date', 'enum', 'enums', 'array', 'keys', 'fluent',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toLowerString();
        if (! in_array($method, self::FORBIDDEN_METHODS, true)) {
            return [];
        }

        if (! (new ObjectType(Request::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message("Donnée non validée : \$request->{$method}() est interdit, utiliser \$request->validated() ou \$request->safe() (02-TRD §6.7).")
                ->identifier('cga.unvalidatedRequestData')
                ->build(),
        ];
    }
}
