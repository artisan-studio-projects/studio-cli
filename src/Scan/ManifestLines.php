<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

/**
 * Where a project names its own dependencies: the line of each package in
 * package.json or composer.json. Audit findings point there, at the package
 * a developer would change, rather than at a lockfile nobody reads.
 */
final class ManifestLines
{
    /**
     * @var array<string, list<string>>
     */
    private const array SECTIONS = [
        'package.json' => ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'],
        'composer.json' => ['require', 'require-dev'],
    ];

    /**
     * @var array<string, int> package => line
     */
    private readonly array $lines;

    public function __construct(string $root, public readonly string $manifest)
    {
        $this->lines = self::read($root.'/'.$manifest, self::SECTIONS[$manifest] ?? []);
    }

    public function has(string $package): bool
    {
        return isset($this->lines[$package]);
    }

    /**
     * The manifest with the package's line, or the manifest alone when it
     * does not name the package.
     */
    public function where(string $package): string
    {
        return $this->manifest.(isset($this->lines[$package]) ? ':'.$this->lines[$package] : '');
    }

    /**
     * @param  list<string>  $sections
     * @return array<string, int>
     */
    private static function read(string $path, array $sections): array
    {
        $rows = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES) : false;
        $lines = [];
        $opened = null;

        foreach ($rows === false ? [] : $rows as $index => $row) {
            $open = preg_match('/^(\s*)"([^"]+)"\s*:\s*\{\s*$/', $row, $section) === 1 && in_array($section[2], $sections, true);
            $close = $opened !== null && preg_match('/^(\s*)\}/', $row, $brace) === 1 && strlen($brace[1]) <= $opened;
            $entry = $opened !== null && ! $close && preg_match('/^\s*"([^"]+)"\s*:/', $row, $package) === 1;

            if ($entry) {
                $lines[$package[1]] ??= $index + 1;
            }

            $opened = match (true) {
                $open => strlen($section[1]),
                $close => null,
                default => $opened,
            };
        }

        return $lines;
    }
}
