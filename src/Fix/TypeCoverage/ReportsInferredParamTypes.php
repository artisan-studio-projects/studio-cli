<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\TypeCoverage;

use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;

/**
 * Reports, for each untyped parameter, the type PHPStan infers for it inside
 * the closure, as one plain native type or not at all.
 */
trait ReportsInferredParamTypes
{
    /**
     * @param  array<Param>  $params
     * @return list<IdentifierRuleError>
     */
    private function inferredTypes(array $params, Scope $scope): array
    {
        return array_values(array_filter(array_map(fn (Param $param): ?IdentifierRuleError => $this->report($param, $scope), $params)));
    }

    private function report(Param $param, Scope $scope): ?IdentifierRuleError
    {
        if ($param->type !== null || $param->byRef || $param->variadic || $param->attrGroups !== [] || ! $param->var instanceof Variable || ! is_string($param->var->name)) {
            return null;
        }

        $native = $this->native($scope->getType(new Variable($param->var->name)));

        return $native === null ? null : RuleErrorBuilder::message((string) json_encode(['param' => $param->var->name, 'type' => $native]))
            ->identifier(ClosureParamTypesRule::IDENTIFIER)
            ->line($param->getStartLine())
            ->build();
    }

    private function native(Type $type): ?string
    {
        $inner = TypeCombinator::removeNull($type);

        if ($type->isNull()->yes() || $inner instanceof NeverType || $inner instanceof MixedType) {
            return null;
        }

        $names = array_values(array_unique($inner instanceof UnionType ? array_map($this->single(...), $inner->getTypes()) : [$this->single($inner)]));

        if ($names === [] || in_array(null, $names, true)) {
            return null;
        }

        $nullable = ! $type->isNull()->no();

        return count($names) === 1 ? ($nullable ? '?' : '').$names[0] : implode('|', [...$names, ...($nullable ? ['null'] : [])]);
    }

    private function single(Type $type): ?string
    {
        $classes = $type->getObjectClassNames();

        return match (true) {
            $type->isString()->yes() => 'string',
            $type->isInteger()->yes() => 'int',
            $type->isFloat()->yes() => 'float',
            $type->isBoolean()->yes() => 'bool',
            $type->isArray()->yes() => 'array',
            $type->isObject()->yes() && count($classes) === 1 => '\\'.$classes[0],
            default => null,
        };
    }
}
