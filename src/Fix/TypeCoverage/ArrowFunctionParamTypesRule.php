<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\TypeCoverage;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InArrowFunctionNode;
use PHPStan\Rules\Rule;

/**
 * Loaded into the project's own PHPStan only while SAMI fixes types. Reports
 * what each untyped `fn ()` parameter is inside its arrow function.
 *
 * @implements Rule<InArrowFunctionNode>
 */
final class ArrowFunctionParamTypesRule implements Rule
{
    use ReportsInferredParamTypes;

    public function getNodeType(): string
    {
        return InArrowFunctionNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return $this->inferredTypes($node->getOriginalNode()->getParams(), $scope);
    }
}
