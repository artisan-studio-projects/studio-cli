<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Fix\PhpStan\ReadModelCasts;
use ArtisanStudio\StudioCli\Scan\Tools\FilaCheck;
use ArtisanStudio\StudioCli\Scan\Tools\Pint;
use ArtisanStudio\StudioCli\Scan\Tools\Rector;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-fixes-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Livewire/Concerns', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->fixes = new AutomatedFixes(new Toolbox($this->root), $this->root);
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
    $this->finding = fn (string $where, string $rule, string $message): array => ['where' => $where, 'rule' => $rule, 'message' => $message];
});

it('describes a computed property on the component, from the method in the class or in a trait it uses', function (): void {
    ($this->write)('app/Livewire/Concerns/ShowsTheCharacter.php', <<<'PHP'
        <?php

        namespace App\Livewire\Concerns;

        use App\Models\Character;
        use Livewire\Attributes\Computed;

        trait ShowsTheCharacter
        {
            #[Computed]
            public function character(): ?Character
            {
                return null;
            }
        }
        PHP);
    ($this->write)('app/Livewire/IdeasStep.php', <<<'PHP'
        <?php

        namespace App\Livewire;

        use App\Livewire\Concerns\ShowsTheCharacter;
        use Illuminate\Support\Collection;
        use Livewire\Attributes\Computed;
        use Livewire\Component;

        #[Layout('layouts.app')]
        class IdeasStep extends Component
        {
            use ShowsTheCharacter;

            #[Computed]
            public function ideas(): Collection
            {
                return collect();
            }

            public function plain(): string
            {
                return $this->ideas->count().$this->character?->name.$this->plain;
            }
        }
        PHP);

    $fixed = $this->fixes->pass('phpstan', [
        ($this->finding)('app/Livewire/IdeasStep.php:23', 'property.notFound', 'Access to an undefined property App\Livewire\IdeasStep::$ideas.'),
        ($this->finding)('app/Livewire/IdeasStep.php:23', 'property.notFound', 'Access to an undefined property App\Livewire\IdeasStep::$ideas.'),
        ($this->finding)('app/Livewire/IdeasStep.php:23', 'property.notFound', 'Access to an undefined property App\Livewire\IdeasStep::$character.'),
        ($this->finding)('app/Livewire/IdeasStep.php:23', 'property.notFound', 'Access to an undefined property App\Livewire\IdeasStep::$plain.'),
    ]);

    expect($fixed)->toBe(['computed properties described' => 3])
        ->and(($this->read)('app/Livewire/IdeasStep.php'))->toContain(<<<'PHP'
            use Livewire\Component;

            /**
             * @property-read Collection $ideas
             * @property-read ?\App\Models\Character $character
             */
            #[Layout('layouts.app')]
            class IdeasStep extends Component
            PHP)
        ->not->toContain('$plain'.PHP_EOL);
});

it('removes an @param for a parameter that is gone, and the type arguments on a type that takes none', function (): void {
    ($this->write)('app/Scopes.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Contracts\Database\Eloquent\Builder;

        class Scopes
        {
            /**
             * @param  Builder<Model>  $query
             * @param  string  $old
             * @param  array{
             *     name: string
             * }  $gone
             * @return Builder<Model>
             */
            public function active(Builder $query): Builder
            {
                return $query;
            }
        }
        PHP);

    $fixed = $this->fixes->pass('phpstan', [
        ($this->finding)('app/Scopes.php:17', 'parameter.notFound', 'PHPDoc tag @param references unknown parameter: $old'),
        ($this->finding)('app/Scopes.php:17', 'parameter.notFound', 'PHPDoc tag @param references unknown parameter: $gone'),
        ($this->finding)('app/Scopes.php:17', 'generics.notGeneric', 'PHPDoc tag @param for parameter $query contains generic type Illuminate\Contracts\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> but interface Illuminate\Contracts\Database\Eloquent\Builder is not generic.'),
        ($this->finding)('app/Scopes.php:17', 'generics.notGeneric', 'PHPDoc tag @return contains generic type Illuminate\Contracts\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> but interface Illuminate\Contracts\Database\Eloquent\Builder is not generic.'),
    ]);

    expect($fixed)->toBe(['stale @param tags removed' => 1, 'type arguments on plain types removed' => 2])
        ->and(($this->read)('app/Scopes.php'))->toContain("     * @param  Builder  \$query\n     * @param  array{\n")
        ->toContain('     * @return Builder'.PHP_EOL)
        ->not->toContain('$old');
});

it('describes the older getter-style computed property the same way', function (): void {
    ($this->write)('app/Livewire/Orders.php', "<?php\n\nnamespace App\\Livewire;\n\nclass Orders\n{\n    public function getTotalProperty(): int\n    {\n        return 1;\n    }\n}\n");

    expect($this->fixes->pass('phpstan', [($this->finding)('app/Livewire/Orders.php:5', 'property.notFound', 'Access to an undefined property App\Livewire\Orders::$total.')]))->toBe(['computed properties described' => 1])
        ->and(($this->read)('app/Livewire/Orders.php'))->toContain("/**\n * @property-read int \$total\n */\nclass Orders");
});

it('adds to a docblock the class already has, and leaves a one-line docblock alone', function (): void {
    ($this->write)('app/Livewire/Totals.php', <<<'PHP'
        <?php

        namespace App\Livewire;

        use Livewire\Attributes\Computed;

        /**
         * Totals for the month.
         */
        class Totals
        {
            #[Computed]
            public function total(): int
            {
                return 1;
            }
        }
        PHP);
    ($this->write)('app/Livewire/Brief.php', "<?php\n\nnamespace App\\Livewire;\n\nuse Livewire\\Attributes\\Computed;\n\n/** Brief. */\nclass Brief\n{\n    #[Computed]\n    public function total(): int\n    {\n        return 1;\n    }\n}\n");

    $this->fixes->pass('phpstan', [
        ($this->finding)('app/Livewire/Totals.php:9', 'property.notFound', 'Access to an undefined property App\Livewire\Totals::$total.'),
        ($this->finding)('app/Livewire/Brief.php:9', 'property.notFound', 'Access to an undefined property App\Livewire\Brief::$total.'),
    ]);

    expect(($this->read)('app/Livewire/Totals.php'))->toContain("/**\n * Totals for the month.\n * @property-read int \$total\n */\nclass Totals")
        ->and(($this->read)('app/Livewire/Brief.php'))->toContain("/** Brief. */\nclass Brief");
});

it('drops ?-> only on the left of ??, where a null cannot throw, and never before a method call', function (): void {
    ($this->write)('app/Report.php', <<<'PHP'
        <?php

        namespace App;

        class Report
        {
            public function lines(?User $user): array
            {
                return [
                    $user?->email ?? 'Nobody',
                    $user?->name(),
                    $user?->team->owner() ?? 'Nobody',
                    $user?->team->name ?? 'No team',
                ];
            }
        }
        PHP);

    $fixed = $this->fixes->pass('phpstan', [
        ($this->finding)('app/Report.php:10', 'nullsafe.neverNull', 'Using nullsafe property access "?->email" on left side of ?? is unnecessary. Use -> instead.'),
        ($this->finding)('app/Report.php:11', 'nullsafe.neverNull', 'Using nullsafe method call on non-nullable type App\User. Use -> instead.'),
        ($this->finding)('app/Report.php:12', 'nullsafe.neverNull', 'Using nullsafe property access "?->team" on left side of ?? is unnecessary. Use -> instead.'),
        ($this->finding)('app/Report.php:13', 'nullsafe.neverNull', 'Using nullsafe property access "?->team" on left side of ?? is unnecessary. Use -> instead.'),
    ]);

    expect($fixed)->toBe(['unneeded ?-> made ->' => 2])
        ->and(($this->read)('app/Report.php'))->toContain("\$user->email ?? 'Nobody'")
        ->toContain('$user?->name()')
        ->toContain("\$user?->team->owner() ?? 'Nobody'")
        ->toContain("\$user->team->name ?? 'No team'");
});

it('types a constant from its literal, and leaves one it cannot read or on a PHP without typed constants', function (): void {
    ($this->write)('app/Limits.php', <<<'PHP'
        <?php

        namespace App;

        class Limits
        {
            public const NAME = 'limits';

            final protected const MOST = -5, LEAST = 1;

            private const TAGS = ['a', 'b'];

            public const FALLBACK = Other::VALUE;

            public const string TYPED = 'x';
        }
        PHP);

    $fixed = $this->fixes->pass('pest-type-coverage', collect([7, 9, 11, 13, 15])
        ->map(fn (int $line): array => ($this->finding)('app/Limits.php:'.$line, 'constant', 'No type declared on this constant.'))
        ->all());

    expect($fixed)->toBe(['constants typed' => 3])
        ->and(($this->read)('app/Limits.php'))->toContain("public const string NAME = 'limits';")
        ->toContain('final protected const int MOST = -5, LEAST = 1;')
        ->toContain("private const array TAGS = ['a', 'b'];")
        ->toContain('public const FALLBACK = Other::VALUE;');

    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.2']]));
    ($this->write)('app/Old.php', "<?php\n\nclass Old\n{\n    public const NAME = 'old';\n}\n");

    expect($this->fixes->pass('pest-type-coverage', [($this->finding)('app/Old.php:5', 'constant', 'No type declared on this constant.')]))->toBe([]);
});

it('tells Larastan to read casts() once, only where the models use it and the config has not said so', function (): void {
    mkdir($this->root.'/app/Models', 0755, true);
    ($this->write)('app/Models/Order.php', "<?php\n\nclass Order\n{\n    protected function casts(): array\n    {\n        return [];\n    }\n}\n");
    ($this->write)('phpstan.neon', "includes:\n    - vendor/larastan/larastan/extension.neon\n\nparameters:\n  paths:\n    - app\n  level: 5\n");
    $readCasts = new ReadModelCasts;

    expect($readCasts->apply($this->root))->toBeTrue()
        ->and(($this->read)('phpstan.neon'))->toBe("includes:\n    - vendor/larastan/larastan/extension.neon\n\nparameters:\n  parseModelCastsMethod: true\n  paths:\n    - app\n  level: 5\n")
        ->and($readCasts->apply($this->root))->toBeFalse();

    ($this->write)('phpstan.neon', "includes:\n    - vendor/larastan/larastan/extension.neon\n");

    expect($readCasts->apply($this->root))->toBeTrue()
        ->and(($this->read)('phpstan.neon'))->toEndWith("\n\nparameters:\n    parseModelCastsMethod: true\n");

    ($this->write)('phpstan.neon', "parameters:\n    level: 5\n");

    expect($readCasts->apply($this->root))->toBeFalse();
});

it('runs each tool\'s own fixer only when it is set up, Rector and Pint before the checks that read the code', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    array_map(fn (string $bin): bool => touch($this->root.'/vendor/bin/'.$bin), ['rector', 'filacheck', 'pint']);

    expect((new Rector)->fixCommand($this->root))->toBeNull()
        ->and((new FilaCheck)->fixCommand($this->root))->toBeNull()
        ->and((new Pint)->canFix($this->root))->toBeTrue();

    touch($this->root.'/rector.php');
    mkdir($this->root.'/app/Filament', 0755, true);

    expect((new Rector)->fixCommand($this->root))->toBe(['vendor/bin/rector', 'process', '--no-progress-bar'])
        ->and((new FilaCheck)->fixCommand($this->root))->toBe(['vendor/bin/filacheck', 'app/Filament', '--fix'])
        ->and($this->fixes->fixable(['pint', 'composer-audit', 'phpstan', 'pest-type-coverage', 'rector', 'node-audit']))->toBe(['pest-type-coverage', 'phpstan', 'rector', 'pint']);
});

it('fixes on a new local branch only from a clean tree, one commit per rule, never pushed', function (): void {
    $git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    $git('init', '--quiet', '--initial-branch=develop');
    $git('config', 'user.email', 'dev@example.com');
    $git('config', 'user.name', 'Developer');
    ($this->write)('app/Order.php', "<?php\n");
    $git('add', '-A');
    $git('commit', '--quiet', '-m', 'start');

    $branch = new LocalBranch($this->root);
    ($this->write)('app/Order.php', "<?php\n// mine\n");

    expect($branch->isRepository())->toBeTrue()
        ->and($branch->isClean())->toBeFalse();

    $git('commit', '--quiet', '-am', 'mine');
    ($this->write)('notes.txt', 'untracked');

    $name = $branch->start('2026-10-06-1412');
    ($this->write)('app/Order.php', "<?php\n// fixed\n");
    $sha = $branch->commit('fix(phpstan): SAMI fixed 1 PHPStan finding automatically', '- 1 thing');

    expect($name)->toBe('sami/fixes-2026-10-06-1412')
        ->and($branch->current())->toBe('sami/fixes-2026-10-06-1412')
        ->and($sha)->toBe($git('rev-parse', 'HEAD'))
        ->and($git('show', '--name-only', '--format=', 'HEAD'))->toBe('app/Order.php')
        ->and($branch->commit('nothing to commit'))->toBeNull()
        ->and($git('remote'))->toBe('');

    $git('switch', '--quiet', 'develop');

    expect($branch->start('2026-10-06-1412'))->toBe('sami/fixes-2026-10-06-1412-2');
});

it('keeps two fixers on one branch apart: each commits only its own files, and new files only when it names them', function (): void {
    $git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    $git('init', '--quiet', '--initial-branch=develop');
    $git('config', 'user.email', 'dev@example.com');
    $git('config', 'user.name', 'Developer');
    ($this->write)('app/Order.php', "<?php\n");
    ($this->write)('package.json', "{}\n");
    $git('add', '-A');
    $git('commit', '--quiet', '-m', 'start');

    $branch = new LocalBranch($this->root);
    ($this->write)('app/Order.php', "<?php\n// fixed\n");
    ($this->write)('package.json', "{\"pnpm\": {}}\n");
    mkdir($this->root.'/public');
    ($this->write)('public/app.js', 'published');

    expect($branch->changed(except: ['package.json']))->toBe(['app/Order.php'])
        ->and($branch->untracked())->toContain('public/app.js');

    $branch->commit('fix: PHP', except: ['package.json']);

    expect($git('show', '--name-only', '--format=', 'HEAD'))->toBe('app/Order.php');

    $branch->commit('fix(security): npm', only: ['package.json'], new: ['public/app.js']);

    expect(explode("\n", $git('show', '--name-only', '--format=', 'HEAD')))->toBe(['package.json', 'public/app.js'])
        ->and($branch->isClean())->toBeTrue();
});
