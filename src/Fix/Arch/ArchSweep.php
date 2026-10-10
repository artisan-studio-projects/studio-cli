<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Arch;

use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\Workbench;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every place in app/ the arch fixers can mend, as findings at their lines,
 * since Pest names only the first place each preset breaks.
 */
final class ArchSweep
{
    /**
     * @param  list<SweepingFixer>  $fixers
     */
    public function __construct(
        private readonly string $root,
        private readonly array $fixers,
    ) {}

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        $bench = new Workbench($this->root);

        return collect($this->files())
            ->filter(fn (string $path): bool => collect($this->fixers)->contains(fn (SweepingFixer $fixer): bool => $fixer->couldMatch((string) @file_get_contents($this->root.'/'.$path))))
            ->mapWithKeys(fn (string $path): array => [$path => $bench->open($path)])
            ->filter()
            ->flatMap(fn (SourceFile $file, string $path): array => collect($this->fixers)
                ->flatMap(fn (SweepingFixer $fixer): array => array_map(
                    fn (int $line): array => ['where' => $path.':'.$line, 'rule' => $fixer->rule(), 'message' => $fixer->message()],
                    $fixer->lines($file),
                ))
                ->all())
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        if (! is_dir($this->root.'/app')) {
            return [];
        }

        return collect(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/app', RecursiveDirectoryIterator::SKIP_DOTS)), false))
            ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file): string => substr($file->getPathname(), strlen($this->root) + 1))
            ->sort()
            ->values()
            ->all();
    }
}
