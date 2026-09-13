<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opens one page of the app the way a signed-in developer would, in its own
 * process so it reads the routes and classes as they are on disk now, and
 * prints whether it rendered. It only reads: one GET, as the app's first user.
 */
class RenderCommand extends Command
{
    public const string SIGNATURE = 'studio:render';

    protected $signature = self::SIGNATURE.'
        {path : The page to open, such as /orders}';

    protected $description = 'Open one page of this app as its first user, read-only, and print whether it rendered as JSON';

    public function handle(): int
    {
        $argument = $this->argument('path');
        $path = $this->filled('/'.ltrim(is_string($argument) ? $argument : '', '/'));
        $status = 500;
        $location = null;
        $exception = null;

        try {
            $user = $this->latestUser();

            if ($user instanceof Authenticatable) {
                Auth::guard()->setUser($user);
            }

            $kernel = $this->laravel->make(Kernel::class);
            $request = Request::create($path, 'GET');
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $location = $response->headers->get('Location');
            $exception = data_get($response, 'exception');
            $kernel->terminate($request, $response);
        } catch (Throwable $thrown) {
            $exception = $thrown;
        }

        $this->line((string) json_encode([
            'path' => $path,
            'status' => $status,
            'redirect' => $status >= 300 && $status < 400 ? $location : null,
            'error' => $exception instanceof Throwable ? class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 600) : ($status >= Response::HTTP_BAD_REQUEST ? 'The page answered '.$status.'.' : null),
            'where' => $exception instanceof Throwable && $status !== Response::HTTP_NOT_FOUND ? $this->where($exception) : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * A path with a placeholder such as {order} gets the route key of the
     * first record of the model it names, so the page opens on real data.
     */
    private function filled(string $path): string
    {
        return (string) preg_replace_callback('/\{(\w+)\??\}/', function (array $match): string {
            $model = 'App\\Models\\'.Str::studly($match[1]);
            $record = class_exists($model) && is_subclass_of($model, Model::class) ? rescue(fn (): ?Model => $model::query()->first(), null, false) : null;

            return $record === null ? $match[0] : rawurlencode((string) $record->getRouteKey());
        }, $path);
    }

    private function latestUser(): ?Model
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && class_exists($model) && is_subclass_of($model, Model::class) ? $model::query()->latest('updated_at')->first() : null;
    }

    private function where(Throwable $exception): ?string
    {
        $root = rtrim(base_path(), '/').'/';
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];

        $frame = collect($frames)->first(fn (array $frame): bool => isset($frame['file'])
            && str_starts_with((string) $frame['file'], $root)
            && ! str_contains((string) $frame['file'], '/vendor/')
            && ! str_contains((string) $frame['file'], '/storage/framework/'));

        return $frame === null ? null : substr((string) ($frame['file'] ?? ''), strlen($root)).':'.($frame['line'] ?? 0);
    }
}
