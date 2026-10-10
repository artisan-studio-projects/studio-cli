<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Stmt\Property;
use Throwable;

/**
 * A property's documented type that turns away what the code assigns.
 *
 * - On a property the class declares, its `@var` takes the assigned type,
 *   when the property's native type already takes it.
 * - On a model, whose columns are documented as `@property` lines, only when
 *   the model's own cast says the same: an `expires_at` cast to `datetime`
 *   holds a Carbon whatever its `@property` said. Without a cast, the column
 *   really is what the docblock says, and the code assigning it is the one
 *   to look at.
 */
final class ProvenPropertyType implements Fixer
{
    private const array CARBON = ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp', 'custom_datetime', 'immutable_custom_datetime'];

    private const array ARRAYS = ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:object', 'encrypted:json', 'json:unicode'];

    public function rule(): string
    {
        return 'assign.propertyType';
    }

    public function label(): string
    {
        return 'property types given what the code really assigns';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        if (preg_match('/^Property ([\w\\\\]+)::\$(\w+) \((.+)\) does not accept (.+)\.$/', $message, $found) !== 1 || ProvenTypes::cutShort($message) || str_starts_with(trim($found[4]), 'array{array{')) {
            return false;
        }

        [, $class, $property, $declared, $assigned] = $found;
        $where = $bench->classFile($class);
        $file = $where === null ? null : $bench->open($where);
        $owner = $file?->classNamed($class);

        if ($file === null || $owner === null || str_contains($assigned, 'mixed')) {
            return false;
        }

        $type = ProvenTypes::writable(ProvenTypes::generalised($assigned));
        $same = ProvenTypes::generalised($assigned) === ProvenTypes::generalised($declared);
        $declaration = collect($owner->stmts)->first(fn (mixed $node): bool => $node instanceof Property && collect($node->props)->contains(fn (mixed $prop): bool => $prop->name->toString() === $property));
        $doc = $declaration instanceof Property ? $declaration->getDocComment() : $owner->getDocComment();
        $span = $doc === null ? null : ($declaration instanceof Property
            ? ProvenTypes::tagType($doc->getText(), 'var')
            : $this->propertyTag($doc->getText(), $property));
        $takes = $declaration instanceof Property
            ? ProvenTypes::nativeTakes($declaration->type, $type, $class)
            : $this->castSays($class, $property, $type);

        if ($doc === null || $span === null || ! $takes) {
            return false;
        }

        $written = $same ? ProvenTypes::covariant(trim($span['type'])) : $file->localise($type);

        return $written !== trim($span['type']) && $file->replace($doc->getStartFilePos() + $span['from'], $doc->getStartFilePos() + $span['to'], $written);
    }

    /**
     * @return array{from: int, to: int, type: string}|null
     */
    private function propertyTag(string $doc, string $property): ?array
    {
        return ProvenTypes::tagType($doc, 'property', $property)
            ?? ProvenTypes::tagType($doc, 'property-read', $property)
            ?? ProvenTypes::tagType($doc, 'property-write', $property);
    }

    private function castSays(string $class, string $property, string $type): bool
    {
        try {
            $declared = class_exists($class) && is_a($class, Model::class, true) ? ((new $class)->getCasts()[$property] ?? null) : null;
        } catch (Throwable) {
            return false;
        }

        if (! is_string($declared)) {
            return false;
        }

        $castClass = ltrim($declared, '\\');
        $cast = str_starts_with(strtolower($declared), 'encrypted:') ? strtolower($declared) : strtolower((string) strtok($declared, ':'));
        $parts = array_values(array_filter(ProvenTypes::parts($type), fn (string $part): bool => $part !== 'null'));

        return $parts !== [] && collect($parts)->every(fn (string $part): bool => match (true) {
            in_array($cast, self::CARBON, true) => is_a(ltrim($part, '\\'), 'DateTimeInterface', true),
            in_array($cast, self::ARRAYS, true) => str_starts_with(ltrim($part, '\\'), 'array') || str_starts_with(ltrim($part, '\\'), 'list') || is_a(ltrim((string) preg_replace('/<.*$/s', '', $part), '\\'), 'ArrayAccess', true),
            in_array($cast, ['bool', 'boolean'], true) => in_array($part, ['bool', 'true', 'false'], true),
            in_array($cast, ['int', 'integer'], true) => str_starts_with($part, 'int'),
            enum_exists($castClass) => ltrim($part, '\\') === $castClass || str_starts_with(ltrim($part, '\\'), $castClass.'::'),
            default => false,
        });
    }
}
