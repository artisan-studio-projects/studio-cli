<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use SimpleXMLElement;
use Throwable;

/**
 * Pest's own arch presets, run as they are, from a test file the studio
 * writes for the run into Laravel's git-ignored testing folder and removes
 * after. Pest stops each preset at its first broken rule, so each preset
 * is at most one finding.
 */
class PestArch extends Tool
{
    public const string PLUGIN = 'vendor/pestphp/pest-plugin-arch';

    public const string FOLDER = 'storage/framework/testing/studio-arch';

    public const array PRESETS = ['php', 'laravel', 'security'];

    private ?string $report = null;

    private ?string $root = null;

    public function key(): string
    {
        return 'pest-arch';
    }

    public function name(): string
    {
        return 'Pest arch presets';
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs Pest with its arch plugin, and Laravel\'s storage/framework/testing folder.';
    }

    public function install(): array
    {
        return ['packages' => ['pestphp/pest'], 'files' => [], 'about' => 'Adds Pest, whose arch presets check your code against PHP\'s, Laravel\'s and its security rules.'];
    }

    public function isSetUp(string $root): bool
    {
        return $this->bin($root, 'pest') !== null && is_dir($root.'/'.self::PLUGIN) && is_dir($root.'/storage/framework/testing');
    }

    public function command(string $root): ?array
    {
        if (! $this->isSetUp($root)) {
            return null;
        }

        $this->root = $root;
        $this->report = sys_get_temp_dir().'/studio-arch-'.bin2hex(random_bytes(6)).'.xml';
        $this->clear();
        @mkdir($root.'/'.self::FOLDER, 0755, true);
        file_put_contents($root.'/'.self::FOLDER.'/ArchTest.php', "<?php\n\n".implode("\n", array_map(fn (string $preset): string => "arch('{$preset}')->preset()->{$preset}();", self::PRESETS))."\n");

        return ['vendor/bin/pest', '--test-directory='.self::FOLDER, self::FOLDER, '--colors=never', '--log-junit', $this->report];
    }

    public function findings(string $output, string $root): ?array
    {
        $this->clear();
        $report = $this->report === null ? null : $this->read($this->report);

        if ($report === null) {
            return null;
        }

        return collect($report->xpath('//testcase[failure]') ?: [])
            ->map(fn (SimpleXMLElement $case): array => $this->broken((string) $case['name'], (string) $case->failure))
            ->values()
            ->all();
    }

    /**
     * @return array{where: string, rule: string, message: string}
     */
    private function broken(string $preset, string $failure): array
    {
        $lines = explode("\n", trim(str_starts_with($failure, $preset) ? substr($failure, strlen($preset)) : $failure));
        $at = collect($lines)->first(fn (string $line): bool => preg_match('~^at (?!vendor/)(\S+?):(\d+)$~', trim($line)) === 1);
        preg_match('~^at (\S+?):(\d+)$~', trim((string) $at), $match);

        return $this->finding($match[1] ?? 'app', isset($match[2]) ? (int) $match[2] : null, 'arch-'.$preset, $lines[0]);
    }

    private function clear(): void
    {
        $folder = $this->root === null ? null : $this->root.'/'.self::FOLDER;

        if ($folder === null || ! is_dir($folder)) {
            return;
        }

        array_map(unlink(...), glob($folder.'/*') ?: []);
        @rmdir($folder);
    }

    private function read(string $path): ?SimpleXMLElement
    {
        try {
            $xml = is_file($path) ? simplexml_load_string((string) file_get_contents($path)) : false;
        } catch (Throwable) {
            $xml = false;
        } finally {
            @unlink($path);
        }

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }
}
