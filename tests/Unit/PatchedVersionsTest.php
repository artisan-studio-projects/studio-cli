<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\Security\PatchedVersions;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;

/*
|--------------------------------------------------------------------------
| Security fixes, targeted
|--------------------------------------------------------------------------
|
| Only the packages an audit flags move, to the least version that clears
| every advisory against them, and never out of the major they are on.
|
*/

function advisory(string $package, string $patched, string ...$installed): array
{
    return ['module_name' => $package, 'patched_versions' => $patched, 'findings' => array_map(fn (string $version): array => ['version' => $version], $installed)];
}

it('moves each flagged package to the least version that clears all its advisories, on its own major', function (): void {
    $plan = PatchedVersions::plan(['advisories' => [
        advisory('axios', '>=1.15.0', '1.12.2'),
        advisory('axios', '>=1.20.0', '1.12.2'),
        advisory('picomatch', '>=2.3.2 <3.0.0 || >=4.0.4', '2.3.1', '4.0.3'),
        advisory('tiny', '>=0.4.2 || >=0.5.0', '0.4.1'),
        advisory('postcss-selector-parser', '>=7.1.6', '6.0.10'),
    ]]);

    expect($plan['patches'])->toBe([
        ['package' => 'axios', 'installed' => '1.12.2', 'required' => '1.20.0'],
        ['package' => 'picomatch', 'installed' => '2.3.1', 'required' => '2.3.2'],
        ['package' => 'picomatch', 'installed' => '4.0.3', 'required' => '4.0.4'],
        ['package' => 'tiny', 'installed' => '0.4.1', 'required' => '0.4.2'],
    ])
        ->and($plan['majors'])->toBe([['package' => 'postcss-selector-parser', 'installed' => '6.0.10', 'needs' => '>=7.1.6']]);
});

it('raises a direct dependency\'s range and overrides a transitive one, each bound to its major', function (): void {
    $manifest = PatchedVersions::applied(
        ['dependencies' => ['axios' => '^1.7.4', 'vite' => '^7.0.4'], 'devDependencies' => ['typescript' => '^5.9.3']],
        [
            ['package' => 'axios', 'installed' => '1.12.2', 'required' => '1.20.0'],
            ['package' => 'postcss', 'installed' => '8.5.6', 'required' => '8.5.23'],
            ['package' => 'tiny', 'installed' => '0.4.1', 'required' => '0.4.2'],
        ],
    );

    expect($manifest['dependencies'])->toBe(['axios' => '^1.20.0', 'vite' => '^7.0.4'])
        ->and($manifest['pnpm']['overrides'])->toBe([
            'postcss@>=8.0.0 <8.5.23' => '^8.5.23',
            'tiny@>=0.4.0 <0.4.2' => '^0.4.2',
        ]);
});

it('offers the security audits as fixable when the project has their lockfiles', function (): void {
    $root = sys_get_temp_dir().'/studio-security-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    touch($root.'/composer.lock');

    expect((new AutomatedFixes(new Toolbox($root), $root))->fixable(['node-audit', 'composer-audit']))->toBe(['composer-audit']);

    touch($root.'/pnpm-lock.yaml');

    expect((new AutomatedFixes(new Toolbox($root), $root))->fixable(['node-audit', 'composer-audit']))->toBe(['composer-audit', 'node-audit']);
});
