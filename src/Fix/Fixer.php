<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

/**
 * One kind of finding SAMI can fix without AI.
 *
 * A fixer answers exactly one rule, edits only the node at the reported line,
 * and says no to anything that is not the shape it knows. The scan that runs
 * afterwards is what proves the fix.
 */
interface Fixer
{
    /** The finding's rule this fixes: a PHPStan identifier, or a type coverage kind. */
    public function rule(): string;

    /** What one fix does, for the report: "described a computed property". */
    public function label(): string;

    public function fix(Workbench $bench, string $path, int $line, string $message): bool;
}
