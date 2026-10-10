<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\Arch\ArchSweep;
use ArtisanStudio\StudioCli\Fix\Arch\DebugLeftover;
use ArtisanStudio\StudioCli\Fix\Arch\ExitInCommand;
use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\Workbench;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;

/*
|--------------------------------------------------------------------------
| SAMI's fixes for Pest's arch presets
|--------------------------------------------------------------------------
|
| Pest names only the first place each preset breaks, so these fixers find
| every other place in app/ themselves, and mend only the shapes that are
| safe: a debug call alone on its line, and a command's exit in handle().
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-arch-fixes-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Console/Commands', 0755, true);
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
    $this->fixWith = function (object $fixer, string $path, int $line): bool {
        $bench = new Workbench($this->root);
        $fixed = $fixer->fix($bench, $path, $line, '');
        $bench->save();

        return $fixed;
    };
});

it('removes a debug call left alone on its line, and leaves one used as a value or a method of the same name', function (): void {
    ($this->write)('app/Orders.php', <<<'PHP'
        <?php

        namespace App;

        class Orders
        {
            public function total(array $lines): int
            {
                dump($lines);
                $shown = print_r($lines, true);
                $this->dd();
                \ray('total');

                return count($lines);
            }
        }
        PHP);

    expect((new DebugLeftover)->lines((new Workbench($this->root))->open('app/Orders.php')))->toBe([9, 12])
        ->and(($this->fixWith)(new DebugLeftover, 'app/Orders.php', 9))->toBeTrue()
        ->and(($this->fixWith)(new DebugLeftover, 'app/Orders.php', 11))->toBeTrue()
        ->and(($this->read)('app/Orders.php'))->toContain("    {\n        \$shown = print_r(\$lines, true);\n        \$this->dd();\n\n        return count(\$lines);")
        ->and(($this->fixWith)(new DebugLeftover, 'app/Orders.php', 9))->toBeFalse();
});

it('turns a command\'s exit with a status in handle() into a return, and leaves exits it cannot be sure of', function (): void {
    ($this->write)('app/Console/Commands/SamiCommand.php', <<<'PHP'
        <?php

        namespace App\Console\Commands;

        use Illuminate\Console\Command;

        class SamiCommand extends Command
        {
            public function handle(): int
            {
                if (! file_exists('x')) {
                    exit(self::FAILURE);
                }

                collect([1])->each(function (): void {
                    exit(1);
                });

                exit('Done');
            }

            private function stop(): void
            {
                exit(1);
            }
        }
        PHP);

    $file = (new Workbench($this->root))->open('app/Console/Commands/SamiCommand.php');

    expect((new ExitInCommand)->lines($file))->toBe([12])
        ->and(($this->fixWith)(new ExitInCommand, 'app/Console/Commands/SamiCommand.php', 12))->toBeTrue()
        ->and(($this->read)('app/Console/Commands/SamiCommand.php'))->toContain("        if (! file_exists('x')) {\n            return self::FAILURE;\n        }")
        ->toContain("exit(1);\n        });")
        ->toContain("exit('Done');")
        ->and(($this->fixWith)(new ExitInCommand, 'app/Console/Commands/SamiCommand.php', 24))->toBeFalse();
});

it('sweeps app/ for every place the arch fixers can mend, and fixes them with the arch findings on the static analysis path', function (): void {
    ($this->write)('app/Orders.php', "<?php\n\nnamespace App;\n\nclass Orders\n{\n    public function total(): int\n    {\n        dd(1);\n\n        return 1;\n    }\n}\n");

    $fixes = new AutomatedFixes(new Toolbox($this->root), $this->root);

    expect((new ArchSweep($this->root, [new DebugLeftover, new ExitInCommand]))->findings())->toBe([
        ['where' => 'app/Orders.php:9', 'rule' => 'debug-call', 'message' => 'A debug call was left in app code.'],
    ])
        ->and(AutomatedFixes::OWN)->toContain('pest-arch')
        ->and($fixes->pass('pest-arch', [['where' => 'app/Orders.php:9', 'rule' => 'debug-call', 'message' => 'x']]))->toBe(['debug calls left behind removed' => 1])
        ->and(($this->read)('app/Orders.php'))->not->toContain('dd(1)');
});

it('only parses files whose text could hold an exit or a debug call, and counts an audit by its packages', function (): void {
    expect((new DebugLeftover)->couldMatch("<?php\n\ndump(\$order);"))->toBeTrue()
        ->and((new DebugLeftover)->couldMatch("<?php\n\n\$this->dumpster();"))->toBeFalse()
        ->and((new ExitInCommand)->couldMatch("<?php\n\nexit(1);"))->toBeTrue()
        ->and((new ExitInCommand)->couldMatch("<?php\n\nreturn \$exitCode;"))->toBeFalse()
        ->and(AutomatedFixes::counted('node-audit', [
            ['where' => 'package.json:7', 'rule' => 'CVE-1', 'message' => 'picomatch 2.3.1 via vite: x (high)'],
            ['where' => 'package.json:6', 'rule' => 'CVE-2', 'message' => 'picomatch 4.0.3 via laravel-vite-plugin: y (high)'],
            ['where' => 'package.json:7', 'rule' => 'CVE-3', 'message' => 'rollup 4.52.5 via vite: z (high)'],
        ]))->toBe(2)
        ->and(AutomatedFixes::counted('phpstan', [['where' => 'a.php:1', 'rule' => 'r', 'message' => 'm'], ['where' => 'a.php:2', 'rule' => 'r', 'message' => 'm']]))->toBe(2);
});
