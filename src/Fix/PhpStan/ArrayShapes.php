<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\PhpStan;

use Symfony\Component\Finder\Finder;

/**
 * Every `array{…}` written in a docblock under app/, with its top-level keys,
 * so a shape PHPStan names in a message can be traced to where it is declared.
 */
final class ArrayShapes
{
    /**
     * @var list<array{path: string, at: int, text: string, keys: list<string>}>|null
     */
    private ?array $shapes = null;

    public function __construct(private readonly string $root) {}

    /**
     * The shapes whose keys start with these, in this order.
     *
     * @param  list<string>  $keys
     * @return list<array{path: string, at: int, text: string, keys: list<string>}>
     */
    public function declaring(array $keys): array
    {
        return array_values(array_filter($this->shapes(), fn (array $shape): bool => $keys !== [] && array_slice($shape['keys'], 0, count($keys)) === $keys));
    }

    /**
     * The top-level keys of a shape, from `array{` to its closing brace or to
     * where the text was cut short.
     *
     * @return list<string>
     */
    public static function keys(string $shape): array
    {
        $body = substr($shape, (int) strpos($shape, '{') + 1);
        $depth = 0;
        $parts = [''];

        foreach (str_split($body) as $character) {
            $depth += match ($character) {
                '{', '<', '(', '[' => 1,
                '}', '>', ')', ']' => -1,
                default => 0,
            };

            if ($depth < 0) {
                break;
            }

            $depth === 0 && $character === ',' ? $parts[] = '' : $parts[array_key_last($parts)] .= $character;
        }

        return array_values(array_filter(array_map(
            fn (string $part): ?string => preg_match('/^\s*(?:\*\s*)?[\'"]?([\w-]+)[\'"]?\??\s*:/', $part, $match) === 1 ? $match[1] : null,
            $parts,
        )));
    }

    /**
     * The shape that starts at `$at` in `$code`, braces included.
     */
    public static function at(string $code, int $at): ?string
    {
        $depth = 0;

        for ($end = $at; $end < strlen($code); $end++) {
            $depth += match ($code[$end]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0 && $code[$end] === '}') {
                return substr($code, $at, $end - $at + 1);
            }
        }

        return null;
    }

    /**
     * @return list<array{path: string, at: int, text: string, keys: list<string>}>
     */
    private function shapes(): array
    {
        if ($this->shapes !== null) {
            return $this->shapes;
        }

        $files = is_dir($this->root.'/app') ? iterator_to_array(Finder::create()->files()->in($this->root.'/app')->name('*.php'), false) : [];

        return $this->shapes = array_values(collect($files)
            ->flatMap(fn ($file): array => $this->shapesIn($file->getRelativePathname(), (string) file_get_contents($file->getPathname())))
            ->all());
    }

    /**
     * @return list<array{path: string, at: int, text: string, keys: list<string>}>
     */
    private function shapesIn(string $relative, string $code): array
    {
        preg_match_all('~/\*\*.*?\*/~s', $code, $docs, PREG_OFFSET_CAPTURE);

        return array_values(collect($docs[0])
            ->flatMap(function (array $doc) use ($code, $relative): array {
                preg_match_all('/array\{/', $doc[0], $opens, PREG_OFFSET_CAPTURE);

                return collect($opens[0])
                    ->map(fn (array $open): int => $doc[1] + $open[1] + strlen('array'))
                    ->map(fn (int $at): ?array => ($text = self::at($code, $at)) === null ? null : ['path' => 'app/'.$relative, 'at' => $at, 'text' => $text, 'keys' => self::keys($text)])
                    ->filter()
                    ->all();
            })
            ->all());
    }
}
