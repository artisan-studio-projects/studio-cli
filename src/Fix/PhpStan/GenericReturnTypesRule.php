<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatement;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Loaded into the project's own PHPStan only while SAMI fixes types, through a
 * config the CLI writes for the run. Reports what a method returns when its
 * native return type is a generic class, such as `Builder` or `Collection`,
 * and no `@return` says what it holds, so every caller reads it as holding
 * `Model` or `mixed`.
 *
 * @implements Rule<MethodReturnStatementsNode>
 */
final class GenericReturnTypesRule implements Rule
{
    public const string IDENTIFIER = 'studio.genericReturnType';

    public function getNodeType(): string
    {
        return MethodReturnStatementsNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();
        $native = $method->getVariants()[0]->getNativeReturnType();
        $returns = $node->getReturnStatements();

        if ($node->isGenerator() || $returns === [] || str_contains((string) $method->getDocComment(), '@return') || ! $this->isGeneric($native)) {
            return [];
        }

        $types = array_map(fn (ReturnStatement $return): ?Type => $return->getReturnNode()->expr === null ? null : $return->getScope()->getType($return->getReturnNode()->expr), $returns);

        if (in_array(null, $types, true)) {
            return [];
        }

        $type = TypeCombinator::union(...$types);
        $written = $type->describe(VerbosityLevel::typeOnly());

        return $written === $native->describe(VerbosityLevel::typeOnly()) || ! str_contains($written, '<')
            ? []
            : [RuleErrorBuilder::message((string) json_encode(['method' => $node->getMethodName(), 'type' => $written]))
                ->identifier(self::IDENTIFIER)
                ->line($node->getStartLine())
                ->build()];
    }

    private function isGeneric(Type $native): bool
    {
        $classes = $native->getObjectClassReflections();

        return $classes !== [] && collect($classes)->every(fn (ClassReflection $class): bool => $class->isGeneric());
    }
}
