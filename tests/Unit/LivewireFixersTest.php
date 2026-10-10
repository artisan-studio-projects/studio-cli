<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\Livewire\LockedProperty;
use ArtisanStudio\StudioCli\Fix\Livewire\MissingWireKey;
use ArtisanStudio\StudioCli\Fix\Workbench;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;

/*
|--------------------------------------------------------------------------
| SAMI's fixes for the studio security scan's Livewire findings
|--------------------------------------------------------------------------
|
| A property is only locked when nothing could be changing it from the
| browser, since locking one the user edits blocks them. A loop is only
| keyed when its body is one element and the key is one the loop already has.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-livewire-fixes-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Livewire', 0755, true);
    mkdir($this->root.'/resources/views/livewire', 0755, true);
    mkdir($this->root.'/tests', 0755, true);
    mkdir($this->root.'/resources/js', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
    $this->fix = function (object $fixer, string $path, int $line): bool {
        $bench = new Workbench($this->root);
        $fixed = $fixer->fix($bench, $path, $line, 'x');
        $bench->save();

        return $fixed;
    };
    $this->component = fn (string $body, string $extra = ''): int|false => ($this->write)('app/Livewire/Invoice.php', "<?php\n\nnamespace App\\Livewire;\n\nuse Livewire\\Component;\n\nclass Invoice extends Component\n{\n{$body}\n{$extra}\n    public function render()\n    {\n        return view('livewire.invoice');\n    }\n}\n");
});

it('locks a property nothing in the project changes from the browser, importing Locked once', function (): void {
    ($this->component)("    public int \$userId = 0;\n");
    ($this->write)('resources/views/livewire/invoice.blade.php', "<div>{{ \$userId }}</div>\n");

    expect(($this->fix)(new LockedProperty($this->root), 'app/Livewire/Invoice.php', 9))->toBeTrue()
        ->and(($this->read)('app/Livewire/Invoice.php'))->toContain('use Livewire\Attributes\Locked;')->toContain("    #[Locked]\n    public int \$userId = 0;");
});

it('leaves a property alone when any view, script or test could be changing it', function (string $path, string $code): void {
    ($this->component)("    public int \$userId = 0;\n");
    ($this->write)($path, $code);

    expect(($this->fix)(new LockedProperty($this->root), 'app/Livewire/Invoice.php', 9))->toBeFalse()
        ->and(($this->read)('app/Livewire/Invoice.php'))->not->toContain('#[Locked]');
})->with([
    'a view binds it' => ['resources/views/livewire/other.blade.php', '<input wire:model="userId">'],
    'a view sets it' => ['resources/views/livewire/other.blade.php', "<button wire:click=\"\$set('userId', 2)\">x</button>"],
    'a view assigns it' => ['resources/views/livewire/other.blade.php', '<button wire:click="userId = 2">x</button>'],
    'a script reads $wire' => ['resources/js/app.js', 'const id = $wire.userId'],
    'a script entangles it' => ['resources/js/app.js', "x = this.\$wire.entangle('userId')"],
    'a test sets it' => ['tests/InvoiceTest.php', "<?php\nLivewire::test(Invoice::class)->set('userId', 5);"],
]);

it('leaves a property alone when the component hooks its updates, validates it, or guards it already', function (string $body, string $extra): void {
    ($this->component)($body, $extra);

    expect(($this->fix)(new LockedProperty($this->root), 'app/Livewire/Invoice.php', 9))->toBeFalse();
})->with([
    'an updated hook' => ["    public int \$userId = 0;\n", "    public function updatedUserId(): void {}\n"],
    'a general hook' => ["    public int \$userId = 0;\n", "    public function updated(\$name): void {}\n"],
    'a rules method' => ["    public int \$userId = 0;\n", "    public function rules(): array\n    {\n        return ['userId' => 'required'];\n    }\n"],
    'a validate call' => ["    public int \$userId = 0;\n", "    public function save(): void\n    {\n        \$this->validate(['userId' => 'integer']);\n    }\n"],
    'a rules property' => ["    public int \$userId = 0;\n    protected array \$rules = ['userId' => 'required'];\n", ''],
    'already locked' => ["    #[\\Livewire\\Attributes\\Locked]\n    public int \$userId = 0;\n", ''],
    'read from the URL' => ["    #[\\Livewire\\Attributes\\Url]\n    public int \$userId = 0;\n", ''],
    'several in one declaration' => ["    public \$userId = 0, \$other = 1;\n", ''],
    'not public' => ["    protected int \$userId = 0;\n", ''],
]);

it('keys a loop row with the item\'s id when the body reads it, else the array key, else its position', function (): void {
    ($this->write)('resources/views/livewire/invoice.blade.php', <<<'BLADE'
        <div>
            @foreach ($items as $item)
                <div>
                    <button wire:click="remove({{ $item->id }})">x</button>
                </div>
            @endforeach
            @foreach ($names as $slug => $name)
                <li><button wire:click="pick('{{ $name }}')">x</button></li>
            @endforeach
            @foreach ($rows as $row)
                <x-row><button wire:click="open">x</button></x-row>
            @endforeach
        </div>
        BLADE);

    $fixer = new MissingWireKey;

    expect(($this->fix)($fixer, 'resources/views/livewire/invoice.blade.php', 2))->toBeTrue()
        ->and(($this->fix)($fixer, 'resources/views/livewire/invoice.blade.php', 7))->toBeTrue()
        ->and(($this->fix)($fixer, 'resources/views/livewire/invoice.blade.php', 10))->toBeTrue();

    $view = ($this->read)('resources/views/livewire/invoice.blade.php');

    expect($view)->toContain('<div wire:key="{{ $item->id }}">')
        ->toContain('<li wire:key="{{ $slug }}">')
        ->toContain('<x-row wire:key="{{ $loop->index }}">');
});

it('leaves a loop alone when its body is not exactly one element', function (string $body): void {
    ($this->write)('resources/views/livewire/invoice.blade.php', "<div>\n@foreach (\$items as \$item)\n{$body}\n@endforeach\n</div>\n");

    expect(($this->fix)(new MissingWireKey, 'resources/views/livewire/invoice.blade.php', 2))->toBeFalse()
        ->and(($this->read)('resources/views/livewire/invoice.blade.php'))->not->toContain('wire:key');
})->with([
    'two elements' => ["<div><button wire:click=\"a\">a</button></div>\n<div>b</div>"],
    'wrapped in @if' => ["@if (\$item->show)\n<div><button wire:click=\"a\">a</button></div>\n@endif"],
    'text beside the element' => ['<div><button wire:click="a">a</button></div> and more'],
    'a void element' => ['<input wire:model="a">'],
]);

it('puts the two Livewire fixes under the studio security scan, run with SAMI\'s own', function (): void {
    $fixes = new AutomatedFixes(new Toolbox($this->root), $this->root);

    expect(AutomatedFixes::OWN)->toContain('studio-security')
        ->and(collect($fixes->fixers('studio-security'))->map->rule()->all())->toBe(['livewire-locked', 'livewire-missing-key']);
});
