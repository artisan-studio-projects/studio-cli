<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Psalm;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\PhpStan\ComputedPropertyDocblock;
use ArtisanStudio\StudioCli\Fix\Workbench;

/**
 * Psalm's word for a Livewire computed property it cannot see: the same fix
 * as PHPStan's, a @property-read with the method's own return type.
 */
final class ComputedProperty implements Fixer
{
    public function __construct(private readonly ComputedPropertyDocblock $docblock = new ComputedPropertyDocblock) {}

    public function rule(): string
    {
        return 'UndefinedThisPropertyFetch';
    }

    public function label(): string
    {
        return $this->docblock->label();
    }

    public function fix(Workbench $bench, string $path, int $line, string $message): bool
    {
        return preg_match('/property ([\w\\\\]+)::\$(\w+)/', $message, $match) === 1
            && $this->docblock->fix($bench, $path, $line, 'Access to an undefined property '.$match[1].'::$'.$match[2].'.');
    }
}
