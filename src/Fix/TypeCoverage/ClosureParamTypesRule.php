<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\TypeCoverage;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClosureNode;
use PHPStan\Rules\Rule;

/**
 * Loaded into the project's own PHPStan only while SAMI fixes types, through a
 * config the CLI writes for the run. Reports what each untyped `function ()`
 * parameter is inside its closure.
 *
 * @implements Rule<InClosureNode>
 */
final class ClosureParamTypesRule implements Rule
{
    use ReportsInferredParamTypes;

    public const string IDENTIFIER = 'studio.closureParamType';

    public function getNodeType(): string
    {
        return InClosureNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return $this->inferredTypes($node->getOriginalNode()->getParams(), $scope);
    }
}
