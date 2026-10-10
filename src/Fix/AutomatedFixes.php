<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\Fix\Arch\ArchSweep;
use ArtisanStudio\StudioCli\Fix\Arch\DebugLeftover;
use ArtisanStudio\StudioCli\Fix\Arch\ExitInCommand;
use ArtisanStudio\StudioCli\Fix\Livewire\LockedProperty;
use ArtisanStudio\StudioCli\Fix\Livewire\MissingWireKey;
use ArtisanStudio\StudioCli\Fix\PhpStan\ArrayShapes;
use ArtisanStudio\StudioCli\Fix\PhpStan\ComputedPropertyDocblock;
use ArtisanStudio\StudioCli\Fix\PhpStan\GenericOnPlainType;
use ArtisanStudio\StudioCli\Fix\PhpStan\InferredGenericReturns;
use ArtisanStudio\StudioCli\Fix\PhpStan\MatchesAlwaysSet;
use ArtisanStudio\StudioCli\Fix\PhpStan\NeverNullNullsafe;
use ArtisanStudio\StudioCli\Fix\PhpStan\OptionalShapeKey;
use ArtisanStudio\StudioCli\Fix\PhpStan\ProvenParamType;
use ArtisanStudio\StudioCli\Fix\PhpStan\ProvenPropertyType;
use ArtisanStudio\StudioCli\Fix\PhpStan\ProvenReturnType;
use ArtisanStudio\StudioCli\Fix\PhpStan\ReadModelCasts;
use ArtisanStudio\StudioCli\Fix\PhpStan\RedundantArrayValues;
use ArtisanStudio\StudioCli\Fix\PhpStan\RefinedReturnType;
use ArtisanStudio\StudioCli\Fix\PhpStan\StaleParamTag;
use ArtisanStudio\StudioCli\Fix\PhpStan\TraitHostProperty;
use ArtisanStudio\StudioCli\Fix\PhpStan\UnusedReturnType;
use ArtisanStudio\StudioCli\Fix\Psalm\ComputedProperty;
use ArtisanStudio\StudioCli\Fix\Psalm\OverrideAttribute;
use ArtisanStudio\StudioCli\Fix\Psalm\RedundantCast;
use ArtisanStudio\StudioCli\Fix\Security\TargetedSecurityUpdates;
use ArtisanStudio\StudioCli\Fix\TypeCoverage\InferredClosureTypes;
use ArtisanStudio\StudioCli\Fix\TypeCoverage\TypedClosureParam;
use ArtisanStudio\StudioCli\Fix\TypeCoverage\TypedConstant;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Illuminate\Support\Collection;

/**
 * Fixes the rules without AI, in one pass.
 *
 * Everything starts from what the scan found, so nothing is checked again
 * while fixing. SAMI's own fixers go file by file: every fix any rule wants in
 * a file lands as one write, at offsets in the text the scan read, so no fix
 * moves another's line. The tools' own fixers, which need no lines, run after.
 */
final class AutomatedFixes
{
    /**
     * Rules SAMI fixes herself, at the lines their tool reported.
     *
     * @var list<string>
     */
    public const array OWN = ['pest-type-coverage', 'phpstan', 'psalm', self::SECURITY_SCAN, self::ARCH];

    public const string SECURITY_SCAN = 'studio-security';

    public const string ARCH = 'pest-arch';

    /**
     * Rules whose tool has its own fixer, run whole after SAMI's, since they need no lines.
     *
     * @var list<string>
     */
    public const array TOOLS = ['filacheck', 'rector', 'pint'];

    /**
     * Audits whose flagged packages SAMI patches within the versions the
     * project allows, first, so everything after runs on the patched code.
     *
     * @var list<string>
     */
    public const array SECURITY = ['composer-audit', 'node-audit'];

    public function __construct(
        private readonly Toolbox $toolbox,
        private readonly string $root,
    ) {}

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function fixable(array $keys): array
    {
        return array_values(array_filter([...self::SECURITY, ...self::OWN, ...self::TOOLS], fn (string $key): bool => in_array($key, $keys, true)
            && ($this->fixers($key) !== [] || $this->toolbox->canFix($key) || $this->security()->canFix($key))));
    }

    public function security(): TargetedSecurityUpdates
    {
        return new TargetedSecurityUpdates($this->root, fn (): array => $this->toolbox->environment());
    }

    /**
     * What the last scan found for each rule, when it read this commit. Rules
     * it has nothing for are checked now, all at the same time.
     *
     * @param  list<string>  $keys
     * @return array{findings: array<string, list<array{where: string, rule: string, message: string}>>, checked: list<string>, failed: array<string, string>}
     */
    public function findings(array $keys): array
    {
        $own = array_values(array_intersect($keys, self::OWN));
        $kept = new LastFindings($this->root);
        $scanned = array_filter(array_combine($own, array_map($kept->for(...), $own)), fn (?array $findings): bool => $findings !== null);
        $missing = array_values(array_diff($own, array_keys($scanned)));
        $checked = $missing === [] ? [] : $this->toolbox->checkTogether($missing);

        $found = [...$scanned, ...array_map(fn (array $result): array => $result['findings'] ?? [], array_filter($checked, fn (array $result): bool => $result['ran']))];

        return [
            'findings' => isset($found[self::ARCH]) ? [...$found, self::ARCH => [...$found[self::ARCH], ...(new ArchSweep($this->root, $this->archFixers()))->findings()]] : $found,
            'checked' => $missing,
            'failed' => array_map(fn (array $result): string => $result['reason'] ?? 'It did not run.', array_filter($checked, fn (array $result): bool => ! $result['ran'])),
        ];
    }

    /**
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     * @return list<Fixer>
     */
    public function fixers(string $key, array $findings = []): array
    {
        return match ($key) {
            'phpstan' => [new ComputedPropertyDocblock, new TraitHostProperty, new NeverNullNullsafe, new StaleParamTag, new GenericOnPlainType, new MatchesAlwaysSet, new OptionalShapeKey(new ArrayShapes($this->root)), new RefinedReturnType, new ProvenReturnType, new ProvenParamType, new ProvenPropertyType, new RedundantArrayValues, new UnusedReturnType],
            'pest-type-coverage' => [new TypedConstant, new TypedClosureParam($this->inferredClosureTypes($findings))],
            'psalm' => [new OverrideAttribute, new ComputedProperty, new RedundantCast],
            self::SECURITY_SCAN => [new LockedProperty($this->root), new MissingWireKey],
            self::ARCH => $this->archFixers(),
            default => [],
        };
    }

    /**
     * @return list<DebugLeftover|ExitInCommand>
     */
    private function archFixers(): array
    {
        return [new DebugLeftover, new ExitInCommand];
    }

    /**
     * How many things a rule's findings are, the way Insights counts them:
     * an audit's advisories are one per package, everything else one each.
     *
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     */
    public static function counted(string $key, array $findings): int
    {
        return in_array($key, self::SECURITY, true)
            ? count(array_unique(array_map(fn (array $finding): string => (string) strtok($finding['message'], ' '), $findings)))
            : count($findings);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function setUp(array $keys): array
    {
        $readCasts = new ReadModelCasts;

        return array_intersect($keys, ['pest-type-coverage', 'phpstan']) !== [] && $readCasts->apply($this->root) ? [$readCasts->label()] : [];
    }

    /**
     * Every fix SAMI can make, file by file across all the rules, written once.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @param  (callable(string, string): void)|null  $onFixed  told the rule and the file each time a fix lands
     * @return array{kinds: array<string, array<string, int>>, files: array<string, int>, saved: list<string>, fixed: array<string, list<array{where: string, rule: string, message: string}>>}
     */
    public function fileByFile(array $findings, ?callable $onFixed = null): array
    {
        $bench = new Workbench($this->root);
        $fixers = collect($findings)
            ->map(fn (array $found, string $key): array => collect($this->fixers($key, $found))->groupBy(fn (Fixer $fixer): string => $fixer->rule())->all());

        $fixed = collect($findings)
            ->flatMap(fn (array $found, string $key): array => array_map(fn (array $finding): array => [...$finding, 'key' => $key], $found))
            ->groupBy(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']))
            ->flatMap(fn (Collection $inFile, string $path): array => $inFile->map(function (array $finding) use ($bench, $fixers, $path, $onFixed): ?array {
                $line = (int) substr((string) strrchr($finding['where'], ':'), 1);
                $label = collect($fixers->get($finding['key'])[$finding['rule']] ?? [])
                    ->first(fn (Fixer $fixer): bool => $fixer->fix($bench, $path, $line, $finding['message']))
                    ?->label();

                if ($label !== null && $onFixed !== null) {
                    $onFixed($finding['key'], $path);
                }

                return $label === null ? null : ['key' => $finding['key'], 'label' => $label, 'path' => $path, 'finding' => array_diff_key($finding, ['key' => true])];
            })->filter()->all());

        $generics = isset($findings['phpstan']) ? new InferredGenericReturns($this->root, fn (): array => $this->toolbox->environment()) : null;
        $typed = $generics?->apply($bench) ?? 0;
        $saved = $bench->save();
        $keys = $saved === [] ? [] : array_values(array_unique([...array_column($fixed->all(), 'key'), ...($typed > 0 ? ['phpstan'] : [])]));
        $of = fn (string $key, string $field): array => array_column(array_filter($fixed->all(), fn (array $fix): bool => $fix['key'] === $key), $field);
        $kinds = array_combine($keys, array_map(fn (string $key): array => array_reduce($of($key, 'label'), fn (array $counts, string $label): array => [...$counts, $label => ($counts[$label] ?? 0) + 1], []), $keys));

        return [
            'kinds' => $typed > 0 && $generics !== null ? [...$kinds, 'phpstan' => [...$kinds['phpstan'] ?? [], $generics->label() => $typed]] : $kinds,
            'files' => array_combine($keys, array_map(fn (string $key): int => count(array_unique($of($key, 'path'))), $keys)),
            'saved' => $saved,
            'fixed' => $fixed->filter(fn (array $fix): bool => in_array($fix['path'], $saved, true))->groupBy('key')->map(fn (Collection $fixes): array => $fixes->pluck('finding')->values()->all())->all(),
        ];
    }

    /**
     * One rule's fixes on their own, for the tests and anyone fixing one rule.
     *
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     * @return array<string, int>
     */
    public function pass(string $key, array $findings): array
    {
        return $this->fileByFile([$key => $findings])['kinds'][$key] ?? [];
    }

    /**
     * Rector's type rules for every rule asked, in one run over every flagged file.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @return array<string, int>
     */
    public function rector(array $findings): array
    {
        $rules = new RectorRules($this->root, fn (): array => $this->toolbox->environment());
        $paths = array_values(array_unique(array_map(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']), array_merge(...array_values($findings)))));
        $changed = $rules->apply(array_keys($findings), $paths);

        return $changed === null || $changed === [] ? [] : [$rules->label() => count($changed)];
    }

    /**
     * Leaves what SAMI changed in the project's own style, when the project
     * has Pint: imports in order, spacing as the rest of the code.
     *
     * @param  list<string>  $changed
     */
    public function tidy(array $changed): void
    {
        $php = array_values(array_filter($changed, fn (string $path): bool => str_ends_with($path, '.php')));

        if ($php !== [] && $this->toolbox->canFix('pint')) {
            $this->toolbox->fix('pint', $php);
        }
    }

    /**
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     * @return array<string, array<int, array<string, string>>>
     */
    private function inferredClosureTypes(array $findings): array
    {
        $paths = array_values(collect($findings)
            ->filter(fn (array $finding): bool => $finding['rule'] === 'parameter')
            ->map(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']))
            ->unique()
            ->all());

        return $paths === [] ? [] : (new InferredClosureTypes($this->root, fn (): array => $this->toolbox->environment()))->in($paths);
    }
}
