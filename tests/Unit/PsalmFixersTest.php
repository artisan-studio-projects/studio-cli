<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\PhpStan\RedundantArrayValues;
use ArtisanStudio\StudioCli\Fix\PhpStan\UnusedReturnType;
use ArtisanStudio\StudioCli\Fix\Psalm\ComputedProperty;
use ArtisanStudio\StudioCli\Fix\Psalm\OverrideAttribute;
use ArtisanStudio\StudioCli\Fix\Psalm\RedundantCast;
use ArtisanStudio\StudioCli\Fix\Workbench;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;

/*
|--------------------------------------------------------------------------
| SAMI's fixes for what Psalm finds
|--------------------------------------------------------------------------
|
| Each answers one Psalm issue type, edits only the method or class at the
| reported line, and says no to anything not the shape it knows.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-psalm-fixes-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Livewire', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
    $this->fixWith = function (object $fixer, string $path, int $line, string $message): bool {
        $bench = new Workbench($this->root);
        $fixed = $fixer->fix($bench, $path, $line, $message);
        $bench->save();

        return $fixed;
    };
});

it('marks a method that overrides its parent\'s with #[\Override], once, keeping its indentation', function (): void {
    ($this->write)('app/Orders.php', <<<'PHP'
        <?php

        namespace App;

        class Orders extends Base
        {
            /**
             * The total.
             */
            #[Deprecated]
            public function total(): int
            {
                return 1;
            }
        }
        PHP);
    $message = 'Method App\Orders::total should have the "Override" attribute';

    expect(($this->fixWith)(new OverrideAttribute, 'app/Orders.php', 11, $message))->toBeTrue()
        ->and(($this->read)('app/Orders.php'))->toContain("     */\n    #[\\Override]\n    #[Deprecated]\n    public function total(): int")
        ->and(($this->fixWith)(new OverrideAttribute, 'app/Orders.php', 12, $message))->toBeFalse()
        ->and(($this->fixWith)(new OverrideAttribute, 'app/Orders.php', 12, 'Method App\Orders::other should have the "Override" attribute'))->toBeFalse();
});

it('describes a Livewire computed property Psalm cannot see, the way the PHPStan fix does', function (): void {
    ($this->write)('app/Livewire/Ideas.php', <<<'PHP'
        <?php

        namespace App\Livewire;

        use Livewire\Attributes\Computed;
        use Livewire\Component;

        class Ideas extends Component
        {
            #[Computed]
            public function count(): int
            {
                return 3;
            }

            public function render(): string
            {
                return (string) $this->count;
            }
        }
        PHP);

    expect(($this->fixWith)(new ComputedProperty, 'app/Livewire/Ideas.php', 18, 'Instance property App\Livewire\Ideas::$count is not defined'))->toBeTrue()
        ->and(($this->read)('app/Livewire/Ideas.php'))->toContain('@property-read int $count');
});

it('fixes Psalm in the same file-by-file pass as PHPStan', function (): void {
    expect((new AutomatedFixes(new Toolbox($this->root), $this->root))->fixers('psalm'))->toHaveCount(3)
        ->and(AutomatedFixes::OWN)->toContain('psalm');
});

it('takes a cast off a parameter the function declares as that type', function (): void {
    ($this->write)('app/Total.php', <<<'PHP'
        <?php

        namespace App;

        class Total
        {
            public function sum(int $a, int $b): int
            {
                return (int) $a + $b;
            }
        }
        PHP);

    $fixed = ($this->fixWith)(new RedundantCast, 'app/Total.php', 9, 'Redundant cast to int');

    expect($fixed)->toBeTrue()
        ->and(($this->read)('app/Total.php'))->toContain('return $a + $b;')->not->toContain('(int)');
});

it('keeps a cast on a model property or a call, whatever the docblock says', function (): void {
    ($this->write)('app/Row.php', <<<'PHP'
        <?php

        namespace App;

        class Row
        {
            public function attempts(Model $row): int
            {
                return (int) $row->attempts;
            }
        }
        PHP);

    expect(($this->fixWith)(new RedundantCast, 'app/Row.php', 9, 'Redundant cast to int'))->toBeFalse()
        ->and(($this->read)('app/Row.php'))->toContain('(int) $row->attempts');
});

it('leaves a line with two casts of the same kind alone', function (): void {
    ($this->write)('app/Pair.php', <<<'PHP'
        <?php

        namespace App;

        class Pair
        {
            public function both(int $a, int $b): array
            {
                return [(int) $a, (int) $b];
            }
        }
        PHP);

    expect(($this->fixWith)(new RedundantCast, 'app/Pair.php', 9, 'Redundant cast to int'))->toBeFalse()
        ->and(($this->read)('app/Pair.php'))->toContain('(int) $a');
});

it('replaces array_values() of a value that is already a list, and leaves a literal alone', function (): void {
    ($this->write)('app/Tags.php', <<<'PHP'
        <?php

        namespace App;

        class Tags
        {
            /**
             * @param  list<string>  $names
             */
            public function all(array $names): array
            {
                return array_values($names);
            }

            public function literal(): array
            {
                return array_values([1, 2]);
            }
        }
        PHP);

    $fixed = ($this->fixWith)(new RedundantArrayValues, 'app/Tags.php', 12, 'Parameter #1 $array (list<string>) of function array_values is already a list, call has no effect.');
    $literal = ($this->fixWith)(new RedundantArrayValues, 'app/Tags.php', 17, 'Parameter #1 $array (list<int>) of function array_values is already a list, call has no effect.');

    expect($fixed)->toBeTrue()
        ->and($literal)->toBeFalse()
        ->and(($this->read)('app/Tags.php'))->toContain('return $names;')->toContain('array_values([1, 2])');
});

it('narrows a return type PHPStan says is never returned, on a class nothing extends', function (): void {
    ($this->write)('app/Label.php', <<<'PHP'
        <?php

        namespace App;

        class Label
        {
            public function text(): string|null
            {
                return 'x';
            }
        }
        PHP);

    $fixed = ($this->fixWith)(new UnusedReturnType, 'app/Label.php', 7, 'Method App\Label::text() never returns null so it can be removed from the return type.');

    expect($fixed)->toBeTrue()
        ->and(($this->read)('app/Label.php'))->toContain('public function text(): string');
});

it('does not narrow a return type on a class something else extends', function (): void {
    ($this->write)('app/Base.php', <<<'PHP'
        <?php

        namespace App;

        class Base
        {
            public function text(): ?string
            {
                return 'x';
            }
        }
        PHP);
    ($this->write)('app/Child.php', "<?php\n\nnamespace App;\n\nclass Child extends Base {}\n");

    expect(($this->fixWith)(new UnusedReturnType, 'app/Base.php', 7, 'Method App\Base::text() never returns null so it can be removed from the return type.'))->toBeFalse()
        ->and(($this->read)('app/Base.php'))->toContain('?string');
});

it('never narrows a nullable return type by taking away the type itself', function (): void {
    ($this->write)('app/Name.php', <<<'PHP'
        <?php

        namespace App;

        class Name
        {
            public function text(): ?string
            {
                return null;
            }
        }
        PHP);

    expect(($this->fixWith)(new UnusedReturnType, 'app/Name.php', 7, 'Method App\Name::text() never returns string so it can be removed from the return type.'))->toBeFalse()
        ->and(($this->read)('app/Name.php'))->toContain('public function text(): ?string');
});
