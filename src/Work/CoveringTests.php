<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\Scan\Tools\TestSuite;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Illuminate\Support\Facades\File;
use SimpleXMLElement;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class CoveringTests
{
    private const int TIMEOUT = 900;

    private const int ALONE_UP_TO = 4;

    private const int LARGEST_EXAMPLE = 20_000;

    public function __construct(private readonly string $root, private readonly Toolbox $toolbox) {}

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function for(array $paths): array
    {
        $names = collect($paths)
            ->filter(fn (string $path): bool => str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php') && ! str_starts_with($path, 'tests/'))
            ->map(fn (string $path): string => basename($path, '.php'))
            ->unique()
            ->values()
            ->all();

        if ($names === [] || ! is_dir($this->root.'/tests')) {
            return [];
        }

        $named = '/\b(?:'.implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names)).')\b/';

        return array_values(collect(File::allFiles($this->root.'/tests'))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Test.php') && preg_match($named, (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => 'tests/'.str_replace('\\', '/', $file->getRelativePathname()))
            ->sort()
            ->all());
    }

    /**
     * @return array{path: string, content: string}|null
     */
    public function example(string $path): ?array
    {
        if (! is_dir($this->root.'/tests/Feature')) {
            return null;
        }

        $words = array_values(array_filter(explode('/', dirname($path)), fn (string $part): bool => ! in_array($part, ['.', 'app', 'resources', 'views'], true)));
        $tests = collect(File::allFiles($this->root.'/tests/Feature'))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Test.php') && $file->getSize() <= self::LARGEST_EXAMPLE)
            ->sortBy(fn (SplFileInfo $file): array => [-count(array_filter($words, fn (string $word): bool => str_contains($file->getRelativePathname(), $word) || str_contains((string) file_get_contents($file->getPathname()), $word))), $file->getSize()]);
        $chosen = $tests->first();

        return $chosen === null ? null : ['path' => 'tests/Feature/'.str_replace('\\', '/', $chosen->getRelativePathname()), 'content' => (string) file_get_contents($chosen->getPathname())];
    }

    /**
     * @param  list<string>  $tests
     * @return list<string>|null
     */
    public function failing(array $tests): ?array
    {
        $failures = $this->failures($tests);

        return $failures === null ? null : array_keys($failures);
    }

    /**
     * @param  list<string>  $tests
     * @return array<string, string>|null
     */
    public function failures(array $tests): ?array
    {
        $report = sys_get_temp_dir().'/studio-work-tests-'.bin2hex(random_bytes(6)).'.xml';
        $pest = is_file($this->root.'/vendor/bin/pest');
        $runner = $pest ? 'vendor/bin/pest' : 'vendor/bin/phpunit';

        if ($tests === [] || ! is_file($this->root.'/'.$runner)) {
            return null;
        }

        $suite = $pest && count($tests) > self::ALONE_UP_TO ? TestSuite::of($this->root, $tests) : null;
        $which = $suite === null ? $tests : ['--parallel', '--processes='.Load::forTests(), '--configuration', $suite];
        $process = new Process([$runner, ...$which, '--log-junit', $report], $this->root, $this->toolbox->environment(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            self::remove($report);

            return null;
        } finally {
            $suite !== null && self::remove($suite);
        }

        return $this->read($report);
    }

    private static function remove(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function read(string $report): ?array
    {
        try {
            $xml = is_file($report) && filesize($report) > 0 ? simplexml_load_string((string) file_get_contents($report)) : false;
        } catch (Throwable) {
            $xml = false;
        } finally {
            self::remove($report);
        }

        if (! $xml instanceof SimpleXMLElement) {
            return null;
        }

        return collect($xml->xpath('//testcase[failure or error]') ?: [])
            ->mapWithKeys(fn (SimpleXMLElement $case): array => [ltrim(str_replace($this->root.'/', '', explode('::', (string) $case['file'])[0]), '/').' › '.trim((string) $case['name']) => trim((string) ($case->failure ?? $case->error))])
            ->all();
    }
}
