<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class LinkCommand extends Command
{
    protected $signature = 'artisan-studio:link
        {--token= : Skip the prompt and use this token}
        {--project= : Skip the picker and use this project slug}
        {--url= : Another studio entirely — ours, working on Artisan Studio itself}';

    protected $description = 'Connect this project to Artisan Studio';

    public function handle(Studio $studio): int
    {
        if (is_string($url = $this->option('url')) && $url !== '') {
            config()->set('studio-cli.url', rtrim($url, '/'));
        }

        $token = $this->stringOption('token') ?: password(
            label: 'Your Artisan Studio token',
            required: true,
            hint: 'Settings → Avatar Studio → API token. It is shown once.',
        );

        try {
            $projects = spin(
                fn (): array => $studio->withToken($token)->projects(),
                'Checking the token',
            );
        } catch (Throwable $failure) {
            $this->components->error($failure->getMessage());

            return self::FAILURE;
        }

        if ($projects === []) {
            $this->components->error('That token works, but there are no projects on it.');

            return self::FAILURE;
        }

        $project = $this->stringOption('project') ?: $this->chooseProject($projects);

        $this->writeEnv([
            'ARTISAN_STUDIO_URL' => (string) config('studio-cli.url'),
            'ARTISAN_STUDIO_TOKEN' => $token,
            'ARTISAN_STUDIO_PROJECT' => $project,
        ]);

        $this->components->info('Linked to '.$this->named($project, $projects).'.');
        $this->components->bulletList([
            'Run `php artisan dev` and the Artisan Studio tab follows your workflows.',
            'Or run `php artisan artisan-studio:watch` on its own.',
        ]);

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    /** @param  list<array{slug: string, name: string, repo: string|null}>  $projects */
    private function chooseProject(array $projects): string
    {
        if (count($projects) === 1) {
            return $projects[0]['slug'];
        }

        return (string) select(
            label: 'Which project is this checkout?',
            options: collect($projects)->mapWithKeys(
                fn (array $project): array => [
                    $project['slug'] => filled($project['repo'] ?? null)
                        ? $project['name'].'  ('.$project['repo'].')'
                        : $project['name'],
                ],
            )->all(),
            scroll: 15,
        );
    }

    /** @param  list<array{slug: string, name: string, repo: string|null}>  $projects */
    private function named(string $project, array $projects): string
    {
        $found = collect($projects)->firstWhere('slug', $project);

        return $found === null ? $project : $found['name'];
    }

    /** @param  array<string, string>  $values */
    private function writeEnv(array $values): void
    {
        $path = $this->laravel->basePath('.env');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote($value);

            $contents = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1
                ? (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }

    private function quote(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
