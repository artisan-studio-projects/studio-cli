<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\PhpStan\ArrayShapes;
use ArtisanStudio\StudioCli\Fix\PhpStan\MatchesAlwaysSet;
use ArtisanStudio\StudioCli\Fix\PhpStan\OptionalShapeKey;
use ArtisanStudio\StudioCli\Fix\PhpStan\RefinedReturnType;
use ArtisanStudio\StudioCli\Fix\PhpStan\TraitHostProperty;
use ArtisanStudio\StudioCli\Fix\SourceFile;
use ArtisanStudio\StudioCli\Fix\TypeCoverage\TypedClosureParam;
use ArtisanStudio\StudioCli\Fix\Workbench;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-fixes-more-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Concerns', 0755, true);
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

it('declares in a trait the property its methods read, copied from the class that has it, importing its type', function (): void {
    ($this->write)('app/Concerns/HasPricing.php', "<?php\n\nnamespace App\\Concerns;\n\ntrait HasPricing\n{\n    public function price(): string\n    {\n        return \$this->resolution.\$this->upload?->name;\n    }\n}\n");
    ($this->write)('app/Video.php', "<?php\n\nnamespace App;\n\nuse App\\Concerns\\HasPricing;\nuse Livewire\\Features\\SupportFileUploads\\TemporaryUploadedFile;\n\nclass Video\n{\n    use HasPricing;\n\n    public string \$resolution = '720p';\n\n    public ?TemporaryUploadedFile \$upload = null;\n}\n");
    ($this->write)('app/Ideas.php', "<?php\n\nnamespace App;\n\nuse App\\Concerns\\HasPricing;\n\nclass Ideas\n{\n    use HasPricing;\n}\n");

    $fixer = new TraitHostProperty;

    expect(($this->fixWith)($fixer, 'app/Concerns/HasPricing.php', 9, 'Access to an undefined property App\Ideas::$resolution.'))->toBeTrue()
        ->and(($this->fixWith)($fixer, 'app/Concerns/HasPricing.php', 9, 'Access to an undefined property App\Ideas::$upload.'))->toBeTrue()
        ->and(($this->read)('app/Concerns/HasPricing.php'))
        ->toContain("public string \$resolution = '720p';")
        ->toContain('public ?TemporaryUploadedFile $upload = null;')
        ->toContain('use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;');
});

it('leaves a trait property alone when the classes using it declare it differently, or nobody does', function (): void {
    ($this->write)('app/Concerns/HasMode.php', "<?php\n\nnamespace App\\Concerns;\n\ntrait HasMode\n{\n    public function mode(): string\n    {\n        return \$this->mode.\$this->missing;\n    }\n}\n");
    ($this->write)('app/One.php', "<?php\n\nnamespace App;\n\nuse App\\Concerns\\HasMode;\n\nclass One\n{\n    use HasMode;\n\n    public string \$mode = 'a';\n}\n");
    ($this->write)('app/Two.php', "<?php\n\nnamespace App;\n\nuse App\\Concerns\\HasMode;\n\nclass Two\n{\n    use HasMode;\n\n    public string \$mode = 'b';\n}\n");

    $fixer = new TraitHostProperty;

    expect(($this->fixWith)($fixer, 'app/Concerns/HasMode.php', 9, 'Access to an undefined property App\One::$mode.'))->toBeFalse()
        ->and(($this->fixWith)($fixer, 'app/Concerns/HasMode.php', 9, 'Access to an undefined property App\One::$missing.'))->toBeFalse()
        ->and(($this->read)('app/Concerns/HasMode.php'))->not->toContain('public string');
});

it('makes a docblock shape honest about a key the code treats as optional, without touching the code', function (): void {
    ($this->write)('app/Report.php', <<<'PHP'
        <?php

        namespace App;

        class Report
        {
            /**
             * @return array{name: string, notes: string, total: int}
             */
            public function row(): array
            {
                return [];
            }

            /**
             * @param  array{
             *     cause: string,
             *     sites: list<string>
             * }  $proposal
             */
            public function write(array $proposal): string
            {
                return $proposal['other'] ?? '';
            }
        }
        PHP);

    $fixer = new OptionalShapeKey(new ArrayShapes($this->root));
    $bench = new Workbench($this->root);

    expect($fixer->fix($bench, 'app/Report.php', 20, "Offset 'notes' on array{name: string, notes: string, total: int} on left side of ?? always exists and is not nullable."))->toBeTrue()
        ->and($fixer->fix($bench, 'app/Report.php', 23, "Offset 'other' on array{cause: string, sites: list<string>} on left side of ?? does not exist."))->toBeTrue()
        ->and($bench->save())->toBe(['app/Report.php'])
        ->and(($this->read)('app/Report.php'))
        ->toContain('@return array{name: string, notes?: string, total: int}')
        ->toContain("     *     sites: list<string>,\n     *     other?: mixed,\n     * }  \$proposal")
        ->toContain("return \$proposal['other'] ?? '';");
});

it('removes a fallback on a preg_match_all group, which PHP always fills, and keeps one after preg_match', function (): void {
    ($this->write)('app/Tags.php', <<<'PHP'
        <?php

        namespace App;

        class Tags
        {
            public function all(string $text): array
            {
                preg_match_all('/#(\w+)/', $text, $matches);
                preg_match('/@(\w+)/', $text, $one);

                return [$matches[1] ?? [], $one[1] ?? null];
            }
        }
        PHP);

    $fixer = new MatchesAlwaysSet;
    $message = 'Offset 1 on array{list<string>, list<non-empty-string>} on left side of ?? always exists and is not nullable.';

    expect(($this->fixWith)($fixer, 'app/Tags.php', 12, $message))->toBeFalse();

    ($this->write)('app/Tags.php', str_replace('[$matches[1] ?? [], $one[1] ?? null]', '$matches[1] ?? []', ($this->read)('app/Tags.php')));

    expect(($this->fixWith)($fixer, 'app/Tags.php', 12, $message))->toBeTrue()
        ->and(($this->read)('app/Tags.php'))->toContain('return $matches[1];');
});

it('gives a @return the refinement PHPStan proved, and leaves a real mismatch alone', function (): void {
    ($this->write)('app/Names.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Support\Collection;

        class Names
        {
            /**
             * @return Collection<int, string>
             */
            public function names(): Collection
            {
                return collect(['a']);
            }
        }
        PHP);

    $fixer = new RefinedReturnType;

    expect(($this->fixWith)($fixer, 'app/Names.php', 14, 'Method App\Names::names() should return Illuminate\Support\Collection<int, int> but returns Illuminate\Support\Collection<int, string>.'))->toBeFalse()
        ->and(($this->fixWith)($fixer, 'app/Names.php', 14, 'Method App\Names::names() should return Illuminate\Support\Collection<int, string> but returns Illuminate\Support\Collection<int, non-empty-string>.'))->toBeTrue()
        ->and(($this->read)('app/Names.php'))->toContain('@return Collection<int, non-empty-string>');
});

it('types a closure parameter with what PHPStan inferred, importing the class once', function (): void {
    ($this->write)('app/Lines.php', "<?php\n\nnamespace App;\n\nclass Lines\n{\n    public function all(\$items): array\n    {\n        return [\$items->map(fn (\$item, \$key) => \$item->title), \$items->map(fn (\$other) => \$other)];\n    }\n}\n");

    $fixer = new TypedClosureParam(['app/Lines.php' => [9 => ['item' => '\App\Models\Insight', 'key' => 'int', 'other' => '?\App\Models\Insight']]]);

    expect(($this->fixWith)($fixer, 'app/Lines.php', 9, 'No type declared on this parameter.'))->toBeTrue()
        ->and(($this->read)('app/Lines.php'))
        ->toContain('fn (Insight $item, int $key) => $item->title')
        ->toContain('fn (?Insight $other) => $other')
        ->and(substr_count(($this->read)('app/Lines.php'), 'use App\Models\Insight;'))->toBe(1);
});

it('never types a closure parameter the closure itself checks the type of', function (): void {
    ($this->write)('app/Files.php', "<?php\n\nnamespace App;\n\nclass Files\n{\n    public function all(\$files): array\n    {\n        return [\$files->map(fn (\$file) => is_array(\$file) ? \$file['path'] : \$file), \$files->filter(fn (\$row) => \$row instanceof \\Stringable)];\n    }\n}\n");

    $fixer = new TypedClosureParam(['app/Files.php' => [9 => ['file' => 'string', 'row' => '\Stringable']]]);

    expect(($this->fixWith)($fixer, 'app/Files.php', 9, 'No type declared on this parameter.'))->toBeFalse()
        ->and(($this->read)('app/Files.php'))->toContain('fn ($file) => is_array($file)')->toContain('fn ($row) => $row instanceof');
});

it('writes a class short where the file imports it, and in full where the short name is taken', function (): void {
    ($this->write)('app/Owner.php', "<?php\n\nnamespace App;\n\nuse Other\\Insight;\n\nclass Owner\n{\n}\n");
    $file = SourceFile::read($this->root.'/app/Owner.php');

    expect($file?->nameFor('\Other\Insight'))->toBe('Insight')
        ->and($file?->nameFor('\App\Models\Insight'))->toBe('\App\Models\Insight')
        ->and($file?->nameFor('\App\Peer'))->toBe('Peer')
        ->and($file?->fullName('Insight'))->toBe('Other\Insight');
});

it('fixes at the scan\'s lines first, then lets Rector and Pint, which need no lines, run whole', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    array_map(fn (string $bin): bool => touch($this->root.'/vendor/bin/'.$bin), ['rector', 'pint']);
    touch($this->root.'/rector.php');

    expect((new AutomatedFixes(new Toolbox($this->root), $this->root))->fixable(['phpstan', 'pest-type-coverage', 'pint', 'rector']))->toBe(['pest-type-coverage', 'phpstan', 'rector', 'pint']);
});

it('fixes every rule in a file in one write, at the lines the scan read, so no fix moves another', function (): void {
    ($this->write)('app/Concerns/HasTotal.php', "<?php\n\nnamespace App\\Concerns;\n\ntrait HasTotal\n{\n}\n");
    ($this->write)('app/Orders.php', <<<'PHP'
        <?php

        namespace App;

        use Livewire\Attributes\Computed;

        class Orders
        {
            const LIMIT = 10;

            #[Computed]
            public function total(): int
            {
                return 3;
            }

            public function shown(): int
            {
                return $this->total + self::LIMIT;
            }
        }
        PHP);

    $fixed = (new AutomatedFixes(new Toolbox($this->root), $this->root))->fileByFile([
        'pest-type-coverage' => [['where' => 'app/Orders.php:9', 'rule' => 'constant', 'message' => 'No type declared on this constant.']],
        'phpstan' => [['where' => 'app/Orders.php:19', 'rule' => 'property.notFound', 'message' => 'Access to an undefined property App\Orders::$total.']],
    ]);

    expect($fixed['kinds'])->toBe(['pest-type-coverage' => ['constants typed' => 1], 'phpstan' => ['computed properties described' => 1]])
        ->and($fixed['files'])->toBe(['pest-type-coverage' => 1, 'phpstan' => 1])
        ->and($fixed['saved'])->toBe(['app/Orders.php'])
        ->and(($this->read)('app/Orders.php'))
        ->toContain('const int LIMIT = 10;')
        ->toContain('@property-read int $total');
});

it('starts from what the last scan kept for this commit, and checks only what it has nothing for', function (): void {
    $git = fn (string ...$arguments): string => (string) (new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput();
    $git('init', '--quiet');
    $git('-c', 'user.email=sami@example.com', '-c', 'user.name=SAMI', 'commit', '--quiet', '--allow-empty', '-m', 'start');

    $kept = new LastFindings($this->root);
    $kept->keep('phpstan', [['where' => 'app/A.php:3', 'rule' => 'property.notFound', 'message' => 'x']]);

    $found = (new AutomatedFixes(new Toolbox($this->root), $this->root))->findings(['phpstan', 'pest-type-coverage']);

    expect($found['findings']['phpstan'])->toBe([['where' => 'app/A.php:3', 'rule' => 'property.notFound', 'message' => 'x']])
        ->and($found['checked'])->toBe(['pest-type-coverage'])
        ->and($found['failed'])->toHaveKey('pest-type-coverage');

    $kept->keep('phpstan', [
        ['where' => 'app/A.php:3', 'rule' => 'property.notFound', 'message' => 'x'],
        ['where' => 'app/B.php:7', 'rule' => 'property.notFound', 'message' => 'y'],
    ]);
    ($this->write)('app/A.php', "<?php\n");
    $git('add', 'app/A.php');
    $git('-c', 'user.email=sami@example.com', '-c', 'user.name=SAMI', 'commit', '--quiet', '-m', 'moved on');

    expect((new LastFindings($this->root))->for('phpstan'))->toBe([['where' => 'app/B.php:7', 'rule' => 'property.notFound', 'message' => 'y']]);

    $git('checkout', '--quiet', '--orphan', 'elsewhere');
    $git('-c', 'user.email=sami@example.com', '-c', 'user.name=SAMI', 'commit', '--quiet', '-m', 'unrelated');

    expect((new LastFindings($this->root))->for('phpstan'))->toBeNull();

    $kept->forget();
});
