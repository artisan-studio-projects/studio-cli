<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\PhpStan\ProvenParamType;
use ArtisanStudio\StudioCli\Fix\PhpStan\ProvenReturnType;
use ArtisanStudio\StudioCli\Fix\PhpStan\ProvenTypes;
use ArtisanStudio\StudioCli\Fix\Workbench;

/*
|--------------------------------------------------------------------------
| Wrong types, fixed from what PHPStan proves
|--------------------------------------------------------------------------
|
| A docblock that says one thing while the code does another takes the type
| PHPStan proves, only where the native type already agrees, and only for
| the project's own methods.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-proven-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
    $this->fixWith = function (object $fixer, string $path, int $line, string $message): bool {
        $bench = new Workbench($this->root);
        $fixed = $fixer->fix($bench, $path, $line, $message);
        $bench->save();

        return $fixed;
    };
});

it('widens literal values to their types and splits unions only outside brackets', function (): void {
    expect(ProvenTypes::generalised("array{heading: '<span>a|b</span>', ok: true, count: 3, range: int<1, max>, 0: 'x'}"))
        ->toBe('array{heading: string, ok: bool, count: int, range: int<1, max>, 0: string}')
        ->and(ProvenTypes::generalised("'ai'|'user'"))->toBe('string')
        ->and(ProvenTypes::generalised('$this(App\Models\Plan)|static(App\Models\Plan)'))->toBe('$this|static')
        ->and(ProvenTypes::parts('Illuminate\Support\Collection<int, string|null>|null'))->toBe(['Illuminate\Support\Collection<int, string|null>', 'null'])
        ->and(ProvenTypes::expectsGiven('Collection<int, string>, Collection<(int|string), non-empty-string>'))->toBe(['Collection<int, string>', 'Collection<(int|string), non-empty-string>']);
});

it('writes global classes with a backslash, and an Eloquent collection that holds no models as the base collection', function (): void {
    expect(ProvenTypes::writable('Illuminate\Support\Collection<int, stdClass>'))->toBe('\Illuminate\Support\Collection<int, \stdClass>')
        ->and(ProvenTypes::writable('Illuminate\Database\Eloquent\Collection<int, string>'))->toBe('\Illuminate\Support\Collection<int, string>')
        ->and(ProvenTypes::writable('Illuminate\Database\Eloquent\Collection<int|string, Illuminate\Database\Eloquent\Collection<int, Illuminate\Foundation\Auth\User>>'))
        ->toBe('\Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Foundation\Auth\User>>')
        ->and(ProvenTypes::writable('array{TModel: string, name: TValue}'))->toBe('array{TModel: string, name: TValue}');
});

it('gives a stale @return the shape the method really returns, across a docblock of several lines', function (): void {
    ($this->write)('app/Handoff.php', <<<'PHP'
        <?php

        namespace App;

        class Handoff
        {
            /**
             * The two artisans either side of the handoff.
             *
             * @return array{
             *     from: Agent,
             *     to: Agent,
             * }|null
             */
            public function current(): ?array
            {
                return ['fromName' => 'Foreman', 'toName' => 'Mason', 'live' => true];
            }
        }
        PHP);

    expect(($this->fixWith)(new ProvenReturnType, 'app/Handoff.php', 17, "Method App\\Handoff::current() should return array{from: App\\Agent, to: App\\Agent}|null but returns array{fromName: 'Foreman', toName: 'Mason', live: true}."))->toBeTrue()
        ->and(($this->read)('app/Handoff.php'))
        ->toContain('@return array{fromName: string, toName: string, live: bool}')
        ->toContain('The two artisans either side of the handoff.')
        ->not->toContain('from: Agent');
});

it('writes a proven collection type with short class names, when the native type takes it', function (): void {
    ($this->write)('app/Names.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Support\Collection;

        class Names
        {
            /**
             * @return Collection<int, string>
             */
            public function all(): Collection
            {
                return collect([1]);
            }
        }
        PHP);

    expect(($this->fixWith)(new ProvenReturnType, 'app/Names.php', 14, 'Method App\Names::all() should return Illuminate\Support\Collection<int, string> but returns Illuminate\Database\Eloquent\Collection<int, App\Models\Plan>.'))->toBeTrue()
        ->and(($this->read)('app/Names.php'))
        ->toContain('@return \Illuminate\Database\Eloquent\Collection<int, Plan>')
        ->toContain('use App\Models\Plan;');
});

it('leaves a return alone when the native type disagrees too, or the proof knows less than the docblock', function (): void {
    ($this->write)('app/Pay.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Support\Collection;

        class Pay
        {
            /**
             * @return array<int, string>
             */
            public function lines(): array
            {
                return collect();
            }

            /**
             * @return array{name: string}
             */
            public function row(): array
            {
                return [];
            }
        }
        PHP);

    expect(($this->fixWith)(new ProvenReturnType, 'app/Pay.php', 14, 'Method App\Pay::lines() should return array<int, string> but returns Illuminate\Support\Collection<int, mixed>.'))->toBeFalse()
        ->and(($this->fixWith)(new ProvenReturnType, 'app/Pay.php', 22, 'Method App\Pay::row() should return array{name: string} but returns array<string, mixed>.'))->toBeFalse()
        ->and(($this->read)('app/Pay.php'))->toContain('@return array<int, string>')->toContain('@return array{name: string}');
});

it('lets a @param take the same values said more precisely, or with the keys a filter left behind', function (): void {
    ($this->write)('app/Search.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Support\Collection;

        class Search
        {
            /**
             * @param  Collection<int, array{path: string, score: int}>  $scored
             * @param  Collection<int, string>  $matches
             * @param  Collection<int, string>  $names
             */
            public function pick(Collection $scored, Collection $matches, Collection $names): void {}
        }
        PHP);

    $fix = fn (string $message): bool => ($this->fixWith)(new ProvenParamType, 'app/Caller.php', 9, $message);

    expect($fix('Parameter #1 $scored of method App\Search::pick() expects Illuminate\Support\Collection<int, array{path: string, score: int}>, Illuminate\Support\Collection<int, array{path: string, score: int<1, max>}> given.'))->toBeTrue()
        ->and($fix('Parameter #2 $matches of method App\Search::pick() expects Illuminate\Support\Collection<int, string>, Illuminate\Support\Collection<(int|string), non-empty-string> given.'))->toBeTrue()
        ->and($fix('Parameter #3 $names of method App\Search::pick() expects Illuminate\Support\Collection<int, string>, Illuminate\Support\Collection<int, App\Models\Plan> given.'))->toBeFalse()
        ->and($fix('Parameter #1 $value of method Illuminate\Support\Collection::push() expects string, int given.'))->toBeFalse()
        ->and(($this->read)('app/Search.php'))
        ->toContain('@param  Collection<int, covariant array{path: string, score: int}>  $scored')
        ->toContain('@param  Collection<array-key, covariant string>  $matches')
        ->toContain('@param  Collection<int, string>  $names');
});

it('lets a constructor take what its callers pass, and a void callback take one that returns something', function (): void {
    ($this->write)('app/Stream.php', <<<'PHP'
        <?php

        namespace App;

        use Closure;
        use Illuminate\Support\Collection;

        class Stream
        {
            /**
             * @param  Collection<int, string>  $lines
             * @param  (Closure(string): void)|null  $flush
             */
            public function __construct(public Collection $lines, public ?Closure $flush = null) {}
        }
        PHP);

    $fix = fn (string $message): bool => ($this->fixWith)(new ProvenParamType, 'app/Caller.php', 4, $message);

    expect($fix('Parameter $lines of class App\Stream constructor expects Illuminate\Support\Collection<int, string>, Illuminate\Support\Collection<int, non-empty-string> given.'))->toBeTrue()
        ->and($fix('Parameter $flush of class App\Stream constructor expects (Closure(string): void)|null, Closure(string): bool given.'))->toBeTrue()
        ->and(($this->read)('app/Stream.php'))
        ->toContain('@param  Collection<int, covariant string>  $lines')
        ->toContain('@param  (Closure(string): mixed)|null  $flush');
});

it('spells a nullable type argument out in full, because Pint cannot read covariant ?string', function (): void {
    expect(ProvenTypes::covariant('Attribute<?string, never>'))->toBe('Attribute<covariant string|null, covariant never>')
        ->and(ProvenTypes::covariant('Collection<int, ?Model>'))->toBe('Collection<covariant int, covariant Model|null>');
});
