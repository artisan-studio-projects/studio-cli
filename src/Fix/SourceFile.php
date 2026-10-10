<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/**
 * One PHP file, read once, changed by small edits at exact offsets.
 *
 * ★ THE FILE IS NEVER REPRINTED. Every fixer finds its node in the parsed tree
 * and edits the text at that node's own offsets, so the developer's formatting,
 * comments and blank lines stay exactly as they were. Edits that would overlap
 * an earlier one are refused rather than merged.
 */
final class SourceFile
{
    /**
     * @var list<array{at: int, until: int, text: string}>
     */
    private array $edits = [];

    /**
     * @var array<int, array{at: int, indent: string, prefix: string, lines: list<string>, suffix: string}>
     */
    private array $docLines = [];

    /**
     * @var array<string, string> class => short name
     */
    private array $newImports = [];

    /**
     * @param  array<Node>  $statements
     */
    private function __construct(
        public readonly string $path,
        public readonly string $code,
        private readonly array $statements,
    ) {}

    /**
     * A file read as plain text, such as a Blade view, which no PHP parser can
     * read: only offset edits are made to it.
     */
    public static function readText(string $path): ?self
    {
        $code = @file_get_contents($path);

        return $code === false ? null : new self($path, $code, []);
    }

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

        $traverser = new NodeTraverser(new NameResolver(options: ['replaceNodes' => false]));

        return new self($path, $code, $traverser->traverse($statements));
    }

    /**
     * @template T of Node
     *
     * @param  class-string<T>  $type
     * @return list<T>
     */
    public function on(int $line, string $type): array
    {
        return array_values(array_filter(
            (new NodeFinder)->findInstanceOf($this->statements, $type),
            fn (Node $node): bool => $node->getStartLine() === $line,
        ));
    }

    /**
     * @template T of Node
     *
     * @param  class-string<T>  $type
     * @return list<T>
     */
    public function spanning(int $line, string $type): array
    {
        return array_values(array_filter(
            (new NodeFinder)->findInstanceOf($this->statements, $type),
            fn (Node $node): bool => $node->getStartLine() <= $line && $node->getEndLine() >= $line,
        ));
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

    public function classNamed(string $class): ?ClassLike
    {
        $found = (new NodeFinder)->findFirst($this->statements, fn (Node $node): bool => $node instanceof ClassLike
            && ltrim((string) $node->namespacedName?->toString(), '\\') === ltrim($class, '\\'));

        return $found instanceof ClassLike ? $found : null;
    }

    public function text(Node $node): string
    {
        return substr($this->code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /**
     * A node's text with every class name written in full, so the same code
     * reads the same from any file whatever each one imports.
     */
    public function canonical(Node $node): string
    {
        $start = $node->getStartFilePos();

        return collect((new NodeFinder)->findInstanceOf([$node], Node\Name::class))
            ->filter(fn (Node\Name $name): bool => $name->getAttribute('resolvedName') instanceof Node\Name)
            ->sortByDesc(fn (Node\Name $name): int => $name->getStartFilePos())
            ->reduce(fn (string $text, Node\Name $name): string => substr_replace(
                $text,
                '\\'.ltrim($name->getAttribute('resolvedName')->toString(), '\\'),
                $name->getStartFilePos() - $start,
                $name->getEndFilePos() - $name->getStartFilePos() + 1,
            ), $this->text($node));
    }

    /**
     * Full class names in a piece of code, written as this file would: short
     * where it imports them, importing them where it can.
     */
    public function localise(string $code): string
    {
        return (string) preg_replace_callback('/\\\\[A-Za-z_][\w\\\\]*/', fn (array $class): string => $this->nameFor($class[0]), $code);
    }

    public function replace(int $at, int $until, string $text): bool
    {
        $clashes = collect($this->edits)->contains(fn (array $edit): bool => $at < $edit['until'] && $edit['at'] < $until);

        if ($clashes || $at < 0 || $until > strlen($this->code) || $at > $until) {
            return false;
        }

        $this->edits[] = ['at' => $at, 'until' => $until, 'text' => $text];

        return true;
    }

    public function insert(int $at, string $text): bool
    {
        return $this->replace($at, $at, $text);
    }

    public function hasDocLine(ClassLike $class, string $line): bool
    {
        $existing = (string) $class->getDocComment()?->getText();

        return str_contains($existing, $line) || in_array($line, $this->docLines[$class->getStartFilePos()]['lines'] ?? [], true);
    }

    /**
     * Adds one line to a class's docblock, starting one where there is none.
     *
     * Lines for the same class gather into one block however many fixes ask,
     * and a one-line docblock is left alone, since there is no tidy place in it.
     */
    public function addDocLine(ClassLike $class, string $line): bool
    {
        if ($this->hasDocLine($class, $line)) {
            return true;
        }

        $key = $class->getStartFilePos();

        if (! isset($this->docLines[$key]) && ! $this->startDocLines($class, $key)) {
            return false;
        }

        $this->docLines[$key]['lines'][] = $line;

        return true;
    }

    /**
     * The name to write for a class here: its short name, importing it when the
     * file does not yet, or the full name when the short one is taken.
     */
    public function nameFor(string $class): string
    {
        $class = ltrim($class, '\\');
        $short = substr((string) strrchr('\\'.$class, '\\'), 1);
        $imports = $this->imports();
        $namespace = (string) collect((new NodeFinder)->findInstanceOf($this->statements, Namespace_::class))->first()?->name?->toString();
        $declared = collect((new NodeFinder)->findInstanceOf($this->statements, ClassLike::class))->map(fn (ClassLike $node): string => (string) $node->name?->toString());

        return match (true) {
            ($imports[strtolower($short)] ?? null) === $class, isset($this->newImports[$class]) => $short,
            isset($imports[strtolower($short)]), $declared->contains($short), in_array($short, $this->newImports, true) => '\\'.$class,
            $namespace !== '' && $namespace.'\\'.$short === $class => $short,
            default => $this->import($class, $short),
        };
    }

    /**
     * What a short class name written in this file means: its import, or the
     * class of that name in the file's own namespace.
     */
    public function fullName(string $short): string
    {
        $namespace = (string) collect((new NodeFinder)->findInstanceOf($this->statements, Namespace_::class))->first()?->name?->toString();

        return $this->imports()[strtolower($short)] ?? ($namespace === '' ? $short : $namespace.'\\'.$short);
    }

    public function changed(): bool
    {
        return $this->edits !== [] || $this->docLines !== [];
    }

    public function save(): bool
    {
        if (! $this->changed()) {
            return false;
        }

        $edits = [
            ...$this->edits,
            ...collect($this->docLines)->map(fn (array $doc): array => [
                'at' => $doc['at'],
                'until' => $doc['at'],
                'text' => $doc['prefix'].implode('', array_map(fn (string $line): string => $doc['indent'].' * '.$line."\n", $doc['lines'])).$doc['suffix'],
            ])->values()->all(),
        ];

        usort($edits, fn (array $a, array $b): int => [$b['at'], $b['until']] <=> [$a['at'], $a['until']]);

        $code = array_reduce($edits, fn (string $code, array $edit): string => substr($code, 0, $edit['at']).$edit['text'].substr($code, $edit['until']), $this->code);

        return @file_put_contents($this->path, $code) !== false;
    }

    /**
     * @return array<string, string> lowercased alias => class
     */
    private function imports(): array
    {
        return collect((new NodeFinder)->findInstanceOf($this->statements, Use_::class))
            ->filter(fn (Use_ $use): bool => $use->type === Use_::TYPE_NORMAL)
            ->flatMap(fn (Use_ $use): array => $use->uses)
            ->mapWithKeys(fn (UseItem $item): array => [strtolower($item->getAlias()->toString()) => $item->name->toString()])
            ->all();
    }

    private function import(string $class, string $short): string
    {
        $after = collect((new NodeFinder)->findInstanceOf($this->statements, Use_::class))->last()
            ?? collect((new NodeFinder)->findInstanceOf($this->statements, Namespace_::class))->first()?->name;

        if ($after === null || ! $this->insert($after->getEndFilePos() + ($after instanceof Use_ ? 1 : 2), "\nuse {$class};")) {
            return '\\'.$class;
        }

        $this->newImports[$class] = $short;

        return $short;
    }

    private function startDocLines(ClassLike $class, int $key): bool
    {
        $doc = $class->getDocComment();
        $lineStart = strrpos(substr($this->code, 0, $key), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = (string) preg_replace('/\S.*$/s', '', substr($this->code, $lineStart, $key - $lineStart));

        if ($doc === null) {
            $this->docLines[$key] = ['at' => $lineStart, 'indent' => $indent, 'prefix' => $indent."/**\n", 'lines' => [], 'suffix' => $indent." */\n"];

            return true;
        }

        $text = $doc->getText();
        $close = strrpos($text, '*/');
        $lastBreak = $close === false ? false : strrpos(substr($text, 0, $close), "\n");

        if ($lastBreak === false) {
            return false;
        }

        $this->docLines[$key] = ['at' => $doc->getStartFilePos() + $lastBreak + 1, 'indent' => $indent, 'prefix' => '', 'lines' => [], 'suffix' => ''];

        return true;
    }
}
