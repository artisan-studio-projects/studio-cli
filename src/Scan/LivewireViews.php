<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Livewire\Components;

/**
 * What the Blade views of Livewire components print and repeat.
 *
 * - {!! !!} on a public property prints something the visitor can change
 *   without escaping it, which lets them run script in other people's pages;
 * - x-html does the same for anything that comes from the server or $wire;
 * - a loop of rows with wire: attributes and no wire:key lets Livewire mix
 *   one row's state up with another's.
 */
final class LivewireViews
{
    public const string UNESCAPED = 'livewire-unescaped-output';

    public const string X_HTML = 'livewire-x-html';

    public const string MISSING_KEY = 'livewire-missing-key';

    private readonly Components $components;

    public function __construct(private readonly string $root, ?Components $components = null)
    {
        $this->components = $components ?? new Components($root);
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function findings(): array
    {
        $found = [];

        foreach (array_keys($this->components->all()) as $component) {
            $editable = $this->editableProperties($component);

            foreach ($this->components->viewFiles($component) as $file) {
                foreach ($this->inView($file, $editable) as $finding) {
                    $found[$finding['where'].'|'.$finding['rule']] = $finding;
                }
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * The public properties the browser can change: not locked.
     *
     * @return list<string>
     */
    private function editableProperties(string $component): array
    {
        return array_values(collect($this->components->properties($component))
            ->filter(fn (array $declared): bool => $declared['property']->isPublic()
                && ! $declared['property']->isStatic()
                && ! $this->components->hasAttribute($declared['property']->attrGroups, ['Livewire\Attributes\Locked']))
            ->flatMap(fn (array $declared): array => array_map(fn ($prop): string => $prop->name->toString(), $declared['property']->props))
            ->unique()
            ->all());
    }

    /**
     * @param  list<string>  $editable
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function inView(string $file, array $editable): array
    {
        $text = (string) @file_get_contents($file);
        $path = ltrim(substr($file, strlen($this->root)), '/');
        $found = [];

        foreach ($this->matches('/\{!!\s*(.*?)\s*!!\}/s', $text) as [$expression, $line]) {
            if ($this->mentions($expression, $editable)) {
                $found[] = ['where' => $path.':'.$line, 'rule' => self::UNESCAPED, 'message' => '{!! '.trim(mb_substr($expression, 0, 60)).' !!} prints a value the browser can change without escaping it, so a visitor can run script in the page. Use {{ }}, or clean it first.'];
            }
        }

        foreach ($this->matches('/x-html\s*=\s*"([^"]*)"/', $text) as [$expression, $line]) {
            if (preg_match('/\$wire|\$this|\{\{|\{!!/', $expression) === 1) {
                $found[] = ['where' => $path.':'.$line, 'rule' => self::X_HTML, 'message' => 'x-html inserts '.trim(mb_substr($expression, 0, 50)).' as raw HTML, so anything a visitor can put in it runs as script. Use x-text, or clean it first.'];
            }
        }

        return [...$found, ...$this->unkeyedLoops($text, $path)];
    }

    /**
     * @param  list<string>  $editable
     */
    private function mentions(string $expression, array $editable): bool
    {
        return collect($editable)->contains(fn (string $property): bool => preg_match('/(?:\$this->|\$)'.preg_quote($property, '/').'(?![\w(])/', $expression) === 1);
    }

    /**
     * @return list<array{string, int}>
     */
    private function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match, array $whole): array => [$match[0], substr_count(substr($text, 0, $whole[1]), "\n") + 1], $found[1], $found[0]);
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    private function unkeyedLoops(string $text, string $path): array
    {
        preg_match_all('/@foreach\b|@endforeach\b/', $text, $tags, PREG_OFFSET_CAPTURE);

        $open = [];
        $found = [];

        foreach ($tags[0] as [$tag, $offset]) {
            if ($tag === '@foreach') {
                $open[] = $offset;

                continue;
            }

            $start = array_pop($open);
            $body = $start === null ? '' : substr($text, $start, $offset - $start);

            if ($start !== null && preg_match('/wire:(model|click|submit)|<livewire:|@livewire/', $body) === 1 && preg_match('/wire:key|:key\s*=|\skey\s*=/', $body) !== 1) {
                $found[] = ['where' => $path.':'.(substr_count(substr($text, 0, $start), "\n") + 1), 'rule' => self::MISSING_KEY, 'message' => 'This loop repeats rows that use wire: attributes but has no wire:key, so Livewire can mix one row\'s state up with another\'s. Add wire:key="{{ $item->id }}" to the row.'];
            }
        }

        return $found;
    }
}
