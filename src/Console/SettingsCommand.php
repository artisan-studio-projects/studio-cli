<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesSettings;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;

class SettingsCommand extends Command implements ProvidesSettings
{
    public const string SIGNATURE = 'studio:settings';

    private const string URL = 'ARTISAN_STUDIO_URL';

    private const string TOKEN = 'ARTISAN_STUDIO_TOKEN';

    private const string PROJECT = 'ARTISAN_STUDIO_PROJECT';

    private const string EDITOR = 'ARTISAN_STUDIO_EDITOR';

    private const string ASK_FOR_A_TOKEN = 'Paste your Artisan Studio token. It is under Settings → Avatar Studio → API token, and shown once.';

    protected $aliases = ['studio:link', 'artisan-studio:link'];

    protected $signature = self::SIGNATURE.'
        {--token= : Link with this token, without opening the studio}
        {--project= : Use this project, by slug, without the picker}
        {--url= : Another studio entirely — ours, working on Artisan Studio itself}';

    protected $description = 'Link this project to Artisan Studio, switch project, and the rest of your settings';

    /**
     * @var list<array{slug: string, name: string, repo: string|null}>|null
     */
    private ?array $projects = null;

    private ?string $problem = null;

    public function handle(): int
    {
        $url = $this->stringOption('url');

        if ($url !== '') {
            config()->set('studio-cli.url', rtrim($url, '/'));
        }

        return match (true) {
            $this->stringOption('token') !== '' => $this->linkFromTheFlags(),
            $this->stringOption('project') !== '' => $this->switchFromTheFlag(),
            default => $this->call(StudioCommand::SIGNATURE, ['tab' => ScreenContainer::SETTINGS]),
        };
    }

    public function settings(Settings $settings): Settings
    {
        return $settings->label('Studio')
            ->state(fn (): array => $this->whereThisCheckoutStands())
            ->components([
                Feed::make(fn (array $state): array => $state['rows'])
                    ->columns([
                        Column::make('label')->width(10)->colour('dim'),
                        Column::make('value')->colour('soft'),
                        Column::make('status')
                            ->width(14)
                            ->colour(fn (array $row): string => $row['colour'])
                            ->formatStateUsing(fn (string $status): string => $status === '' ? '' : "● {$status}"),
                    ]),
                Text::make(fn (array $state): string => (string) $state['problem'])->colour('rose')->wrap(),
            ])
            ->actions([
                Action::make('switch')
                    ->label('Switch project')
                    ->visible(fn (array $state): bool => $state['linked'] && $state['choices'] !== [])
                    ->options(fn (array $state): array => $state['choices'])
                    ->action(fn (string $project): string => $this->switchTo($project)),
                Action::make('link')
                    ->label('Link with a token')
                    ->visible(fn (array $state): bool => ! $state['linked'])
                    ->asks(self::ASK_FOR_A_TOKEN, secret: true)
                    ->action(fn (string $token): ?string => $this->linkWith($token))
                    ->then('switch'),
                Action::make('relink')
                    ->label('Link with a new token')
                    ->visible(fn (array $state): bool => $state['linked'])
                    ->asks(self::ASK_FOR_A_TOKEN, secret: true)
                    ->action(fn (string $token): ?string => $this->linkWith($token))
                    ->then('switch'),
                Action::make('editor')
                    ->label('Choose your editor')
                    ->options(fn (array $state): array => $state['editors'])
                    ->action(fn (string $editor): string => $this->useEditor($editor)),
                Action::make('unlink')
                    ->label('Unlink this project')
                    ->visible(fn (array $state): bool => $state['linked'])
                    ->requiresConfirmation('This takes the token and project out of your .env.')
                    ->action(fn (): string => $this->unlink()),
            ]);
    }

    /**
     * @return array{linked: bool, rows: list<array{label: string, value: string, status: string, colour: string}>, problem: ?string, choices: array<string, string>, editors: array<string, string>}
     */
    private function whereThisCheckoutStands(): array
    {
        $linked = app(Studio::class)->isLinked();
        $this->projects = $linked && ($this->projects ?? []) === [] ? $this->projectsFor(null) : $this->projects;
        $current = (string) config('studio-cli.project', '');
        $editor = app(Editor::class);

        return [
            'linked' => $linked,
            'rows' => [
                ...($linked ? $this->linkedRows($current) : $this->unlinkedRows()),
                ['label' => 'Editor', 'value' => $editor->describe(), 'status' => '', 'colour' => 'dim'],
            ],
            'problem' => $this->problem,
            'choices' => collect($this->projects ?? [])
                ->mapWithKeys(fn (array $project): array => [$project['slug'] => ($project['slug'] === $current ? '● ' : '  ').$this->describe($project)])
                ->all(),
            'editors' => collect(Editor::CHOICES)
                ->mapWithKeys(fn (string $label, string $key): array => [$key => ($key === $editor->chosen() ? '● ' : '  ').$label])
                ->all(),
        ];
    }

    private function useEditor(string $editor): ?string
    {
        if (! isset(Editor::CHOICES[$editor])) {
            return null;
        }

        app(EnvFile::class)->write([self::EDITOR => $editor]);
        config()->set('studio-cli.review.editor', $editor);

        return $editor === Editor::NONE
            ? 'Reviews list the files for you to open.'
            : 'Reviews open their files in '.Editor::CHOICES[$editor].'.';
    }

    /**
     * @return list<array{label: string, value: string, status: string, colour: string}>
     */
    private function linkedRows(string $current): array
    {
        $project = collect($this->projects ?? [])->firstWhere('slug', $current);
        $token = (string) config('studio-cli.token');
        $accepted = $this->problem === null;

        return [
            ['label' => 'Project', 'value' => $project === null ? $current : $this->describe($project), 'status' => 'linked', 'colour' => 'green'],
            ['label' => 'Studio', 'value' => (string) config('studio-cli.url'), 'status' => '', 'colour' => 'dim'],
            ['label' => 'Token', 'value' => str_repeat('•', 8).mb_substr($token, -4), 'status' => $accepted ? 'accepted' : '', 'colour' => 'green'],
        ];
    }

    /**
     * @return list<array{label: string, value: string, status: string, colour: string}>
     */
    private function unlinkedRows(): array
    {
        return [
            ['label' => 'Project', 'value' => 'This project is not linked yet', 'status' => 'not linked', 'colour' => 'amber'],
            ['label' => 'Studio', 'value' => (string) config('studio-cli.url'), 'status' => '', 'colour' => 'dim'],
        ];
    }

    private function linkFromTheFlags(): int
    {
        $token = $this->stringOption('token');
        $projects = $this->projectsFor($token);

        if ($projects === []) {
            $this->components->error((string) $this->problem);

            return self::FAILURE;
        }

        $this->components->info($this->link($token, $this->stringOption('project') ?: $this->chooseProject($projects), $projects));
        $this->components->bulletList(['Run `php artisan studio` to follow your workflows, insights and activity.']);

        return self::SUCCESS;
    }

    private function switchFromTheFlag(): int
    {
        $project = $this->stringOption('project');
        $known = app(Studio::class)->isLinked() && collect($this->projectsFor(null))->contains('slug', $project);

        if (! $known) {
            $this->components->error($this->problem ?? "There is no project called {$project} on your token. Run php artisan studio:settings to see yours.");

            return self::FAILURE;
        }

        $this->components->info($this->switchTo($project));

        return self::SUCCESS;
    }

    private function linkWith(string $token): ?string
    {
        $projects = $this->projectsFor($token);
        $current = (string) config('studio-cli.project', '');
        $project = collect($projects)->contains('slug', $current) ? $current : ($projects[0]['slug'] ?? null);

        return $project === null ? null : $this->link($token, $project, $projects);
    }

    /**
     * @param  list<array{slug: string, name: string, repo: string|null}>  $projects
     */
    private function link(string $token, string $project, array $projects): string
    {
        app(EnvFile::class)->write([self::URL => (string) config('studio-cli.url'), self::TOKEN => $token, self::PROJECT => $project]);
        config()->set(['studio-cli.token' => $token, 'studio-cli.project' => $project]);
        $this->projects = $projects;
        $this->problem = null;

        return 'Linked to '.$this->named($project).'.';
    }

    private function switchTo(string $project): string
    {
        app(EnvFile::class)->write([self::PROJECT => $project]);
        config()->set('studio-cli.project', $project);

        return 'Switched to '.$this->named($project).'.';
    }

    private function unlink(): string
    {
        app(EnvFile::class)->forget([self::TOKEN, self::PROJECT]);
        config()->set(['studio-cli.token' => null, 'studio-cli.project' => null]);
        $this->projects = null;
        $this->problem = null;

        return 'Unlinked this project.';
    }

    /**
     * @return list<array{slug: string, name: string, repo: string|null}>
     */
    private function projectsFor(?string $token): array
    {
        try {
            $projects = ($token === null ? app(Studio::class) : app(Studio::class)->withToken($token))->projects();
        } catch (Throwable $refused) {
            $this->problem = $refused->getMessage();

            return [];
        }

        $this->problem = $projects === [] ? 'That token works, but there are no projects on it.' : null;

        return $projects;
    }

    /**
     * @param  list<array{slug: string, name: string, repo: string|null}>  $projects
     */
    private function chooseProject(array $projects): string
    {
        if (count($projects) === 1) {
            return $projects[0]['slug'];
        }

        return (string) select(
            label: 'Which Artisan Studio project is this?',
            options: collect($projects)->mapWithKeys(fn (array $project): array => [$project['slug'] => $this->describe($project)])->all(),
            scroll: 15,
        );
    }

    /**
     * @param  array{slug: string, name: string, repo: string|null}  $project
     */
    private function describe(array $project): string
    {
        return filled($project['repo'] ?? null) ? $project['name'].'  ('.$project['repo'].')' : $project['name'];
    }

    private function named(string $project): string
    {
        $found = collect($this->projects ?? [])->firstWhere('slug', $project);

        return $found === null ? $project : $found['name'];
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
