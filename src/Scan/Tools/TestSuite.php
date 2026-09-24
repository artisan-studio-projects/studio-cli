<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The project's own PHPUnit configuration with its suites swapped for one
 * that holds only these test files, so a parallel run, which takes a single
 * path, runs just them.
 */
final class TestSuite
{
    /**
     * @param  list<string>  $tests
     */
    public static function of(string $root, array $tests): ?string
    {
        $own = collect(['phpunit.xml', 'phpunit.xml.dist'])->first(fn (string $name): bool => is_file($root.'/'.$name));
        $xml = new DOMDocument;

        if ($own === null || ! @$xml->load($root.'/'.$own) || ! $xml->documentElement instanceof DOMElement) {
            return null;
        }

        $suites = $xml->createElement('testsuites');
        $suite = $xml->createElement('testsuite');
        $suite->setAttribute('name', 'studio');
        array_map(fn (string $test): mixed => $suite->appendChild($xml->createElement('file', $test)), $tests);
        $suites->appendChild($suite);
        $old = $xml->getElementsByTagName('testsuites')->item(0);
        $old === null ? $xml->documentElement->appendChild($suites) : $xml->documentElement->replaceChild($suites, $old);
        self::anchored($xml, $root);
        $file = sys_get_temp_dir().'/studio-suite-'.bin2hex(random_bytes(4)).'.xml';

        return $xml->save($file) === false ? null : $file;
    }

    public static function remove(?string $file): void
    {
        if ($file !== null && is_file($file)) {
            unlink($file);
        }
    }

    private static function anchored(DOMDocument $xml, string $root): void
    {
        $paths = (new DOMXPath($xml))->query('//@bootstrap | //@cacheDirectory | //@cacheResultFile | //@outputDirectory | //@outputFile | //directory | //file');

        collect($paths === false ? [] : iterator_to_array($paths))
            ->each(function (DOMNode $node) use ($root): void {
                $path = trim((string) $node->nodeValue);
                $node->nodeValue = $path === '' || str_starts_with($path, '/') ? $path : $root.'/'.(string) preg_replace('#^\./#', '', $path);
            });
    }
}
