<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Reading the types PHPStan prints, and writing them back into docblocks.
 *
 * PHPStan names every class in full and proves values down to literals. A
 * docblock wants the type, not the value: `'ai'|'user'` is a `string` there.
 */
final class ProvenTypes
{
    private const array SCALARS = [
        'string' => ['string', 'non-empty-string', 'non-falsy-string', 'literal-string', 'lowercase-string', 'uppercase-string', 'numeric-string', 'truthy-string', 'class-string', 'callable-string'],
        'int' => ['int', 'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'non-zero-int'],
        'array' => ['array', 'list', 'non-empty-array', 'non-empty-list'],
        'bool' => ['bool', 'true', 'false'],
    ];

    /**
     * The parts of a union, split only where the `|` is not inside brackets.
     *
     * @return list<string>
     */
    public static function parts(string $type): array
    {
        $parts = [];
        $depth = 0;
        $from = 0;

        foreach (str_split($type) as $at => $char) {
            $depth += self::depthChange($char);

            if ($char === '|' && $depth === 0) {
                $parts[] = trim(substr($type, $from, $at - $from));
                $from = $at + 1;
            }
        }

        return [...$parts, trim(substr($type, $from))];
    }

    /**
     * The type with its literal values widened to their types, so a docblock
     * says `string` where PHPStan proved `'Preparing questions'`.
     */
    public static function generalised(string $type): string
    {
        $ranges = [];
        $kept = (string) preg_replace_callback('/\bint<[^<>]*>/', function (array $range) use (&$ranges): string {
            $ranges[] = $range[0];

            return "\0".(count($ranges) - 1)."\0";
        }, $type);

        $widened = (string) preg_replace(
            ["/'(?:[^'\\\\]|\\\\.)*'/", '/(?<![\w\\\\$\0-])-?\d+(?:\.\d+)?(?![\w\\\\\0]|\s*:)/', '/\b(?:true|false)\b/'],
            ['string', 'int', 'bool'],
            $kept,
        );

        $restored = (string) preg_replace_callback("/\0(\d+)\0/", fn (array $at): string => $ranges[(int) $at[1]], $widened);
        $selves = (string) preg_replace(['/\$this\([^()]*\)/', '/\bstatic\([^()]*\)/'], ['$this', 'static'], $restored);

        return (string) preg_replace('/\b(string|int|bool|float)(?:\|\1\b)+/', '$1', $selves);
    }

    /**
     * The type ready to write in a namespaced file: every class name led by a
     * backslash, global ones like `stdClass` included, so the file's imports
     * decide how it is spelled; and an Eloquent collection that no longer
     * holds models, after a `map()` or `groupBy()`, written as the base
     * collection it still is, since Eloquent's only takes models.
     */
    public static function writable(string $type): string
    {
        $qualified = (string) preg_replace_callback(
            '/(?<![\w\\\\$-])([A-Za-z_]\w*(?:\\\\\w+)*)(?![\w\\\\-]|\s*:)/',
            fn (array $name): string => str_contains($name[1], '\\') || class_exists($name[1]) || interface_exists($name[1]) ? '\\'.$name[1] : $name[1],
            $type,
        );

        return self::modelsOnlyInEloquentCollections($qualified);
    }

    private static function modelsOnlyInEloquentCollections(string $type): string
    {
        $eloquent = '\\Illuminate\\Database\\Eloquent\\Collection<';
        $at = strpos($type, $eloquent);

        if ($at === false) {
            return $type;
        }

        $open = $at + strlen($eloquent) - 1;
        $close = self::closing($type, $open);
        $arguments = $close === null ? [] : self::arguments(substr($type, $open + 1, $close - $open - 1));
        $value = ltrim((string) end($arguments), '\\');
        $isClass = preg_match('/^[A-Za-z_][\w\\\\]*$/', $value) === 1 && ! collect(self::SCALARS)->flatten()->contains($value) && ! in_array($value, ['float', 'mixed', 'object', 'null'], true);
        $holdsModels = $isClass && (! class_exists($value) || is_a($value, 'Illuminate\Database\Eloquent\Model', true));
        $inner = $close === null ? '' : self::modelsOnlyInEloquentCollections(substr($type, $open + 1, $close - $open - 1));

        return $close === null
            ? $type
            : substr($type, 0, $at).($holdsModels ? $eloquent : '\\Illuminate\\Support\\Collection<').$inner.'>'.self::modelsOnlyInEloquentCollections(substr($type, $close + 1));
    }

    private static function closing(string $type, int $open): ?int
    {
        $depth = 0;

        for ($at = $open; $at < strlen($type); $at++) {
            $depth += self::depthChange($type[$at]);

            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function arguments(string $inside): array
    {
        $parts = [];
        $depth = 0;
        $from = 0;

        foreach (str_split($inside) as $at => $char) {
            $depth += self::depthChange($char);

            if ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($inside, $from, $at - $from));
                $from = $at + 1;
            }
        }

        return [...$parts, trim(substr($inside, $from))];
    }

    /**
     * Whether a message was cut short by PHPStan outside a quoted literal,
     * where the missing part would be a type rather than a value.
     */
    public static function cutShort(string $message): bool
    {
        return str_contains((string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "''", $message), '…');
    }

    /**
     * A collection type that takes any subtype of its values, for when PHPStan
     * proves the same type it was told and still turns it away: a `covariant`
     * collection passed through, which its messages do not print.
     */
    public static function covariant(string $type): string
    {
        return implode('|', array_map(function (string $part): string {
            $open = strpos($part, '<');
            $close = $open === false ? null : self::closing($part, $open);

            if ($open === false || $close === null || preg_match('/^\??\\\\?[A-Za-z_][\w\\\\]*$/', substr($part, 0, $open)) !== 1 || in_array(strtolower(ltrim(substr($part, 0, $open), '?\\')), ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable', 'int', 'class-string'], true)) {
                return $part;
            }

            $arguments = array_map(fn (string $argument): string => preg_match('/^(?:covariant|contravariant)\b|^\*$/', $argument) === 1 ? $argument : 'covariant '.self::spelledNullable($argument), self::arguments(substr($part, $open + 1, $close - $open - 1)));

            return substr($part, 0, $open).'<'.implode(', ', $arguments).'>'.substr($part, $close + 1);
        }, self::parts($type)));
    }

    /**
     * `?string` as `string|null`: after `covariant`, the short form is one
     * PHPStan reads but Pint's docblock parser cannot, so Pint stops.
     */
    private static function spelledNullable(string $type): string
    {
        return str_starts_with($type, '?') && ! str_contains($type, '|') ? substr($type, 1).'|null' : $type;
    }

    /**
     * Two types as one union, each part once.
     */
    public static function union(string $first, string $second): string
    {
        return implode('|', array_values(array_unique([...self::parts($first), ...self::parts($second)])));
    }

    /**
     * "expects X, Y given": the two types, split at the comma between them,
     * not one inside `Collection<int, string>`.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function expectsGiven(string $both): ?array
    {
        $depth = 0;
        $split = null;

        foreach (str_split($both) as $at => $char) {
            $depth += self::depthChange($char);
            $split = $depth === 0 && $char === ',' ? $at : $split;
        }

        return $split === null ? null : [trim(substr($both, 0, $split)), trim(substr($both, $split + 1))];
    }

    /**
     * Whether a declared native type takes every part of a proven type.
     */
    public static function nativeTakes(?Node $native, string $type, string $self): bool
    {
        if ($native === null) {
            return false;
        }

        $takes = self::natives($native, $self);

        return in_array('mixed', $takes, true)
            || collect(self::parts($type))->every(fn (string $part): bool => collect($takes)->contains(fn (string $taken): bool => self::takesOne($taken, self::base($part))));
    }

    /**
     * Where a tag's type sits in a docblock, across lines if it spans them.
     *
     * @return array{from: int, to: int, type: string}|null
     */
    public static function tagType(string $doc, string $tag, ?string $variable = null): ?array
    {
        $offset = 0;

        while (preg_match('/@'.preg_quote($tag, '/').'[ \t]+/', $doc, $found, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $from = $found[0][1] + strlen($found[0][0]);
            $to = self::typeEnd($doc, $from);
            $offset = $to;

            if ($to === null) {
                return null;
            }

            $after = substr($doc, $to, 200);

            if ($variable === null || preg_match('/^\s+&?(?:\.\.\.)?\$'.preg_quote($variable, '/').'\b/', $after) === 1) {
                return ['from' => $from, 'to' => $to, 'type' => (string) preg_replace('/\s*\n\s*\*\s*/', ' ', substr($doc, $from, $to - $from))];
            }
        }

        return null;
    }

    private static function typeEnd(string $doc, int $from): ?int
    {
        $depth = 0;

        for ($at = $from; $at < strlen($doc); $at++) {
            $char = $doc[$at];
            $depth += self::depthChange($char);

            if ($depth < 0) {
                return null;
            }

            if ($depth === 0 && ctype_space($char) && ! self::continuesUnion($doc, $at)) {
                return $at;
            }
        }

        return null;
    }

    private static function continuesUnion(string $doc, int $at): bool
    {
        $before = rtrim(substr($doc, 0, $at));
        $after = ltrim(substr($doc, $at));

        return str_ends_with($before, '|') || str_starts_with($after, '|');
    }

    private static function depthChange(string $char): int
    {
        return match ($char) {
            '<', '{', '(', '[' => 1,
            '>', '}', ')', ']' => -1,
            default => 0,
        };
    }

    private static function base(string $part): string
    {
        $part = ltrim(trim($part), '\\');

        return match (true) {
            str_starts_with($part, "'") => 'string',
            preg_match('/^-?\d/', $part) === 1 => 'int',
            preg_match('/^int</', $part) === 1 => 'int',
            preg_match('/^(?:object|array)\{/', $part) === 1 => str_starts_with($part, 'object') ? 'object' : 'array',
            preg_match('/^Closure\(/', $part) === 1 => 'Closure',
            preg_match('/^\$this\(([^)]+)\)/', $part, $self) === 1 => $self[1],
            preg_match('/^static\(([^)]+)\)/', $part, $self) === 1 => $self[1],
            default => (string) preg_replace('/[<{(].*$/s', '', $part),
        };
    }

    private static function takesOne(string $taken, string $base): bool
    {
        $scalar = collect(self::SCALARS)->search(fn (array $names): bool => in_array($base, $names, true));

        return match (true) {
            $taken === $base => true,
            $scalar !== false => $taken === $scalar || ($taken === 'iterable' && $scalar === 'array'),
            $base === 'null' => $taken === 'null',
            $taken === 'object' => ! in_array($base, ['float', 'null', 'callable'], true),
            $taken === 'iterable' => is_a($base, 'Traversable', true),
            default => class_exists($base) || interface_exists($base) ? is_a($base, $taken, true) : false,
        };
    }

    /**
     * @return list<string>
     */
    private static function natives(Node $native, string $self): array
    {
        return match (true) {
            $native instanceof NullableType => [...self::natives($native->type, $self), 'null'],
            $native instanceof UnionType => array_merge(...array_map(fn (Node $type): array => self::natives($type, $self), $native->types)),
            $native instanceof IntersectionType, $native instanceof ComplexType => [],
            $native instanceof Identifier => [match ($native->toLowerString()) {
                'static', 'self' => $self,
                'false', 'true' => 'bool',
                default => $native->toLowerString(),
            }],
            $native instanceof Name => [in_array($native->toLowerString(), ['static', 'self'], true) ? $self : ltrim(self::resolved($native), '\\')],
            default => [],
        };
    }

    private static function resolved(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }
}
