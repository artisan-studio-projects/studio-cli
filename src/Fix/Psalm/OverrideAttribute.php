<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Psalm;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\Workbench;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * A method Psalm proved overrides its parent's gets #[\Override], on PHP 8.3
 * and later, so renaming the parent's method breaks loudly instead of
 * silently leaving this one behind.
 */
final class OverrideAttribute implements Fixer
{
    public function rule(): string
    {
        return 'MissingOverrideAttribute';
    }

    public function label(): string
    {
        return 'overriding methods marked #[\Override]';
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        $file = $bench->runsPhp('8.3') ? $bench->open($path) : null;
        $name = preg_match('/::(\w+)\b/', $message, $found) === 1 ? strtolower($found[1]) : null;
        $method = collect($file?->spanning($line, ClassMethod::class) ?? [])->first(fn (ClassMethod $method): bool => $method->name->toLowerString() === $name);

        if ($file === null || ! $method instanceof ClassMethod || $this->hasOverride($method)) {
            return false;
        }

        $at = $method->getStartFilePos();
        $lineStart = strrpos(substr($file->code, 0, $at), "\n");
        $indent = substr($file->code, $lineStart === false ? 0 : $lineStart + 1, $at - ($lineStart === false ? 0 : $lineStart + 1));

        return trim($indent) === '' && $file->insert($at, '#[\Override]'."\n".$indent);
    }

    private function hasOverride(ClassMethod $method): bool
    {
        return collect($method->attrGroups)
            ->flatMap(fn (AttributeGroup $group): array => $group->attrs)
            ->contains(fn (Attribute $attribute): bool => strtolower(ltrim($attribute->name->toString(), '\\')) === 'override');
    }
}
