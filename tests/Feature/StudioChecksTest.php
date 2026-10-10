<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\AiAccess;
use ArtisanStudio\StudioCli\Scan\LivewireLocks;
use ArtisanStudio\StudioCli\Scan\LivewireSafety;
use ArtisanStudio\StudioCli\Scan\Secrets;
use ArtisanStudio\StudioCli\Scan\Tools\StudioSecurityScan;
use ArtisanStudio\StudioCli\Scan\Tools\Vet;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The studio's own checks
|--------------------------------------------------------------------------
|
| Run inside the project through studio:check, because the tools behind
| them print nothing a machine can read. Only the kind of problem and
| where it is ever leaves: never a secret, never a config value.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-checks-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet');
    $this->key = 'AKIA'.strtoupper(substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWZ234567', 2)), 0, 16));
});

it('finds keys committed to tracked files and a committed .env, never sending the secret, and passes over documented examples', function (): void {
    file_put_contents($this->root.'/app/Aws.php', "<?php\n\n\$key = '{$this->key}';\n");
    file_put_contents($this->root.'/app/Docs.php', "<?php\n\n\$example = 'AKIAIOSFODNN7EXAMPLE';\n");
    file_put_contents($this->root.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($this->root.'/.env.example', "APP_KEY=\n");
    file_put_contents($this->root.'/untracked.php', "<?php\n\$key = '{$this->key}';\n");
    ($this->git)('add', 'app', '.env', '.env.example');

    $findings = (new Secrets($this->root))->findings();

    expect(collect($findings)->pluck('where')->sort()->values()->all())->toBe(['.env:1', 'app/Aws.php:3'])
        ->and(collect($findings)->pluck('rule')->sort()->values()->all())->toBe(['aws-access-key', 'committed-env'])
        ->and(json_encode($findings))->not->toContain($this->key);
});

it('says when an AI agent the project uses can read its .env, and stops once it is denied', function (): void {
    file_put_contents($this->root.'/CLAUDE.md', '# Project');
    mkdir($this->root.'/.cursor');

    expect(collect((new AiAccess($this->root))->findings())->pluck('rule')->all())->toBe(['ai-reads-env:claude-code', 'ai-reads-env:cursor']);

    mkdir($this->root.'/.claude');
    file_put_contents($this->root.'/.claude/settings.json', (string) json_encode(['permissions' => ['deny' => ['Read(./.env)', 'Read(./.env.*)']]]));
    file_put_contents($this->root.'/.cursorignore', ".env\n.env.*\n");

    expect((new AiAccess($this->root))->findings())->toBe([]);
});

it('lists the packages Laravel Vet has no trust record for at the version now locked', function (): void {
    file_put_contents($this->root.'/composer.lock', (string) json_encode(['packages' => [
        ['name' => 'acme/logger', 'version' => 'v1.2.0'],
        ['name' => 'acme/trusted', 'version' => '2.0.0'],
        ['name' => 'acme/changed', 'version' => '3.1.0'],
    ]]));
    file_put_contents($this->root.'/vet.json', (string) json_encode(['require' => ['acme/trusted' => ['version' => '2.0.0'], 'acme/changed' => ['version' => '3.0.0']]]));
    app()->setBasePath($this->root);

    Artisan::call('studio:check', ['check' => 'vet']);
    $findings = json_decode(Artisan::output(), true)['findings'];

    expect(collect($findings)->pluck('message')->all())->toBe(['acme/logger 1.2.0 has not been vetted yet.', 'acme/changed changed from 3.0.0 to 3.1.0 since it was vetted.']);
});

it('reads studio:check\'s JSON as findings, and runs only when the project has what the check needs', function (): void {
    $output = (string) json_encode(['findings' => [['where' => 'app/Aws.php:3', 'rule' => 'aws-access-key', 'message' => 'Looks like an AWS access key.']]]);

    expect((new StudioSecurityScan)->findings($output, $this->root))->toBe([['where' => 'app/Aws.php:3', 'rule' => 'aws-access-key', 'message' => 'Looks like an AWS access key.']])
        ->and((new StudioSecurityScan)->findings('{"error":"x"}', $this->root))->toBeNull()
        ->and((new StudioSecurityScan)->command($this->root))->toBeNull()
        ->and((new Vet)->command($this->root))->toBeNull();

    file_put_contents($this->root.'/artisan', '<?php');

    expect((new StudioSecurityScan)->command($this->root))->toContain('studio:check')->toContain('secrets');
});

it('flags the public Livewire properties the page never edits, and passes over the ones it binds, locks or hands to Livewire to guard', function (): void {
    mkdir($this->root.'/app/Livewire', 0755, true);
    mkdir($this->root.'/resources/views/livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Invoice.php', <<<'PHP'
        <?php

        namespace App\Livewire;

        use App\Models\User;
        use Livewire\Attributes\Locked;
        use Livewire\Attributes\Url;
        use Livewire\Component;

        class Invoice extends Component
        {
            public int $userId = 0;

            public string $role = 'client';

            public string $note = '';

            public bool $open = false;

            public int $page = 1;

            #[Locked]
            public int $accountId = 0;

            #[Url]
            public string $search = '';

            public User $owner;

            public static int $count = 0;

            protected int $secret = 0;

            public function render()
            {
                return view('livewire.invoice');
            }
        }
        PHP);
    file_put_contents($this->root.'/resources/views/livewire/invoice.blade.php', <<<'BLADE'
        <div>
            <input wire:model.live="note">
            <button wire:click="open = true">Open</button>
            <div x-data="{ go: () => $wire.page = 2 }"></div>
            {{ $userId }} {{ $role }}
        </div>
        BLADE);
    ($this->git)('add', 'app', 'resources');

    $findings = (new LivewireLocks($this->root))->findings();

    expect(collect($findings)->pluck('where')->all())->toBe(['app/Livewire/Invoice.php:12', 'app/Livewire/Invoice.php:14', 'app/Livewire/Invoice.php:28'])
        ->and(collect($findings)->pluck('rule')->unique()->all())->toBe(['livewire-locked'])
        ->and($findings[0]['message'])->toContain('App\Livewire\Invoice::$userId')->toContain('#[Locked]')
        ->and($findings[2]['message'])->toContain('whole model');
});

it('reads the views a Livewire page includes, and leaves a page that binds by a computed name alone', function (): void {
    mkdir($this->root.'/app/Livewire', 0755, true);
    mkdir($this->root.'/resources/views/livewire/parts', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Profile.php', "<?php\n\nnamespace App\\Livewire;\n\nuse Livewire\\Component;\n\nclass Profile extends Component\n{\n    public string \$name = '';\n}\n");
    file_put_contents($this->root.'/resources/views/livewire/profile.blade.php', "<div>@include('livewire.parts.name')</div>\n");
    file_put_contents($this->root.'/resources/views/livewire/parts/name.blade.php', "<input wire:model=\"name\">\n");
    file_put_contents($this->root.'/app/Livewire/Dynamic.php', "<?php\n\nnamespace App\\Livewire;\n\nuse Livewire\\Component;\n\nclass Dynamic extends Component\n{\n    public string \$title = '';\n}\n");
    file_put_contents($this->root.'/resources/views/livewire/dynamic.blade.php', "<input wire:model=\"{{ \$field }}\">\n");

    expect((new LivewireLocks($this->root))->findings())->toBe([]);
});

it('flags a trait\'s property only when no component using it binds it', function (): void {
    mkdir($this->root.'/app/Livewire/Concerns', 0755, true);
    mkdir($this->root.'/resources/views/livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Concerns/HasStep.php', "<?php\n\nnamespace App\\Livewire\\Concerns;\n\ntrait HasStep\n{\n    public int \$step = 1;\n\n    public int \$ownerId = 0;\n}\n");
    file_put_contents($this->root.'/app/Livewire/Wizard.php', "<?php\n\nnamespace App\\Livewire;\n\nuse App\\Livewire\\Concerns\\HasStep;\nuse Livewire\\Component;\n\nclass Wizard extends Component\n{\n    use HasStep;\n}\n");
    file_put_contents($this->root.'/resources/views/livewire/wizard.blade.php', "<button wire:click=\"\$set('step', 2)\">Next</button>\n");

    $findings = (new LivewireLocks($this->root))->findings();

    expect(collect($findings)->pluck('where')->all())->toBe(['app/Livewire/Concerns/HasStep.php:9'])
        ->and($findings[0]['message'])->toContain('HasStep::$ownerId');
});

it('flags the Livewire actions that trust what the browser sends, and passes over the ones that check', function (): void {
    mkdir($this->root.'/app/Livewire', 0755, true);
    mkdir($this->root.'/resources/views/livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Posts.php', <<<'PHP'
        <?php

        namespace App\Livewire;

        use App\Models\Post;
        use Illuminate\Support\Facades\RateLimiter;
        use Livewire\Component;

        class Posts extends Component
        {
            public string $title = '';

            public string $apiKey = '';

            public function remove(int $id): void
            {
                Post::findOrFail($id)->delete();
            }

            public function removeMine(int $id): void
            {
                $post = Post::findOrFail($id);
                $this->authorize('delete', $post);
                $post->delete();
            }

            public function save(): void
            {
                Post::create(['title' => $this->title]);
            }

            public function saveAll(): void
            {
                $this->validate(['title' => 'required']);
                Post::create($this->all());
            }

            public function search(): array
            {
                return Post::whereRaw("title like '%{$this->title}%'")->get()->all();
            }

            public function login(): void
            {
                //
            }

            public function verifyCode(): void
            {
                RateLimiter::hit('code');
            }

            public function render()
            {
                return view('livewire.posts');
            }
        }
        PHP);
    file_put_contents($this->root.'/resources/views/livewire/posts.blade.php', "<div>\n    <input wire:model=\"title\">\n    <input wire:model=\"apiKey\">\n    {!! \$title !!}\n    @foreach (\$posts as \$post)\n        <button wire:click=\"remove({{ \$post->id }})\">x</button>\n    @endforeach\n    @foreach (\$posts as \$post)\n        <div wire:key=\"{{ \$post->id }}\"><button wire:click=\"remove(1)\">x</button></div>\n    @endforeach\n</div>\n");

    $found = collect((new LivewireSafety($this->root))->findings())->groupBy('rule')->map(fn ($group): array => $group->pluck('where')->all())->all();

    expect($found['livewire-unauthorized-action'])->toBe(['app/Livewire/Posts.php:15'])
        ->and($found['livewire-unvalidated-input'])->toBe(['app/Livewire/Posts.php:27'])
        ->and($found['livewire-mass-assignment'])->toBe(['app/Livewire/Posts.php:35'])
        ->and($found['livewire-raw-sql'])->toBe(['app/Livewire/Posts.php:40'])
        ->and($found['livewire-no-rate-limit'])->toBe(['app/Livewire/Posts.php:43'])
        ->and($found['livewire-secret-property'])->toBe(['app/Livewire/Posts.php:13'])
        ->and($found['livewire-unescaped-output'])->toBe(['resources/views/livewire/posts.blade.php:4'])
        ->and($found['livewire-missing-key'])->toBe(['resources/views/livewire/posts.blade.php:5']);
});

it('flags a file upload nothing checks, one stored under the visitor\'s name, and a Livewire with a known flaw', function (): void {
    mkdir($this->root.'/app/Livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Avatar.php', "<?php\n\nnamespace App\\Livewire;\n\nuse Livewire\\Component;\nuse Livewire\\WithFileUploads;\n\nclass Avatar extends Component\n{\n    use WithFileUploads;\n\n    public \$photo;\n\n    public function save(): void\n    {\n        \$this->photo->storeAs('avatars', \$this->photo->getClientOriginalName());\n    }\n}\n");
    file_put_contents($this->root.'/composer.lock', (string) json_encode(['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.6.1']]]));

    $found = collect((new LivewireSafety($this->root))->findings())->pluck('rule')->sort()->values()->all();

    expect($found)->toBe(['livewire-outdated', 'livewire-upload-original-name', 'livewire-upload-unvalidated']);
});
