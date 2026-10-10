<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Arch;

use ArtisanStudio\StudioCli\Fix\Fixer;
use ArtisanStudio\StudioCli\Fix\SourceFile;

/**
 * A fixer that finds its own lines. Pest stops each arch preset at its first
 * broken rule, so the fixes go looking for every other place themselves.
 */
interface SweepingFixer extends Fixer
{
    /**
     * @return list<int>
     */
    public function lines(SourceFile $file): array;

    /** What the finding for one place says. */
    public function message(): string;

    /** Whether a file's text could hold a place this fixer mends, so files that cannot are never parsed. */
    public function couldMatch(string $code): bool;
}
