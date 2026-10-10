<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Livewire;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Whether anything in a project's views, scripts or tests reaches a Livewire
 * property by name: bound, read through $wire, set, entangled, or set by a
 * test. A property any of that touches may be meant to change from the
 * browser, so it is never locked.
 *
 * It looks at every view and script, not only the component's own, so a
 * property bound in a view the scan could not follow is still left alone.
 */
final class ProjectUsage
{
    private const int LARGEST_FILE = 1_500_000;

    private ?string $text = null;

    public function __construct(private readonly string $root) {}

    public function mentions(string $property): bool
    {
        $name = preg_quote($property, '/');
        $text = $this->text();

        return preg_match('/\$wire\.'.$name.'(?![\w])/', $text) === 1
            || preg_match('/\$wire\.\$(?:set|toggle|get|entangle|watch)\(\s*[\'"]'.$name.'\b/', $text) === 1
            || preg_match('/(?:\$set|\$toggle|entangle|\$watch)\(\s*[\'"]'.$name.'\b/', $text) === 1
            || preg_match('/\.set\(\s*[\'"]'.$name.'[\'"]/', $text) === 1
            || preg_match('/wire:[\w.\-:]+\s*=\s*(?:"[^"]*|\'[^\']*)(?<![\w$])'.$name.'(?![\w(])/', $text) === 1
            || preg_match('/x-model[\w.\-:]*\s*=\s*["\'][^"\']*\b'.$name.'\b/', $text) === 1
            || preg_match('/->set\(\s*(?:\[[^\]]*)?[\'"]'.$name.'[\'"]/', $text) === 1;
    }

    private function text(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        $this->text = '';

        foreach (['resources', 'tests'] as $folder) {
            if (! is_dir($this->root.'/'.$folder)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'ts', 'vue', 'jsx', 'tsx'], true) && $file->getSize() <= self::LARGEST_FILE) {
                    $this->text .= "\n".(string) @file_get_contents($file->getPathname());
                }
            }
        }

        return $this->text;
    }
}
