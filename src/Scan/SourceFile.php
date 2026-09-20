<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/**
 * One PHP file, parsed once with every class name resolved, for the scan to
 * read. Nothing here edits a file: fixes are made in the studio.
 */
final class SourceFile
{
    /**
     * @param  array<Node>  $statements
     */
    private function __construct(private readonly array $statements) {}

    public static function read(string $path): ?self
    {
        $code = @file_get_contents($path);

        if ($code === false) {
            return null;
        }

        try {
            $statements = (new ParserFactory)->createForHostVersion()->parse($code) ?? [];
        } catch (Throwable) {
            return null;
        }

        return new self((new NodeTraverser(new NameResolver(options: ['replaceNodes' => false])))->traverse($statements));
    }

    /**
     * @template T of Node
     *
     * @param  class-string<T>  $type
     * @return list<T>
     */
    public function all(string $type): array
    {
        return array_values((new NodeFinder)->findInstanceOf($this->statements, $type));
    }
}
