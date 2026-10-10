<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Rector rules SAMI chooses, run on only the files a check flagged, with a
 * config of its own: the project's rector.php is never read or changed.
 *
 * These are rules that add a type Rector can prove from the code around it,
 * such as a closure's parameter from the collection it is passed to. A rule
 * this project's Rector does not have is skipped, not guessed at.
 */
final class RectorRules
{
    public const array RULES = [
        'phpstan' => [
            'RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector' => 'vendor/driftingly/rector-laravel/src/Rector/ClassMethod/AddGenericReturnTypeToRelationsRector.php',
        ],
        'pest-type-coverage' => [
            'Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeFromIterableMethodCallRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/FunctionLike/AddClosureParamTypeFromIterableMethodCallRector.php',
            'Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayMapRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/FunctionLike/AddClosureParamTypeForArrayMapRector.php',
            'Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayReduceRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/FunctionLike/AddClosureParamTypeForArrayReduceRector.php',
            'Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeFromVariableCallRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/FunctionLike/AddClosureParamTypeFromVariableCallRector.php',
            'Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/FuncCall/AddArrayFunctionClosureParamTypeRector.php',
            'Rector\TypeDeclaration\Rector\FuncCall\AddArrayAnyAllClosureParamTypeRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/FuncCall/AddArrayAnyAllClosureParamTypeRector.php',
            'RectorLaravel\Rector\MethodCall\EloquentWhereTypeHintClosureParameterRector' => 'vendor/driftingly/rector-laravel/src/Rector/MethodCall/EloquentWhereTypeHintClosureParameterRector.php',
            ...self::STRICT_RETURNS,
        ],
    ];

    /**
     * Return types Rector adds only where every return proves them.
     */
    private const array STRICT_RETURNS = [
        'Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/AddVoidReturnTypeWhereNoReturnRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\BoolReturnTypeFromBooleanStrictReturnsRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/BoolReturnTypeFromBooleanStrictReturnsRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\BoolReturnTypeFromBooleanConstReturnsRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/BoolReturnTypeFromBooleanConstReturnsRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\NumericReturnTypeFromStrictScalarReturnsRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/NumericReturnTypeFromStrictScalarReturnsRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\StringReturnTypeFromStrictScalarReturnsRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/StringReturnTypeFromStrictScalarReturnsRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\StringReturnTypeFromStrictStringReturnsRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/StringReturnTypeFromStrictStringReturnsRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromReturnNewRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnDirectArrayRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromReturnDirectArrayRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnCastRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromReturnCastRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictConstantReturnRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromStrictConstantReturnRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNativeCallRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromStrictNativeCallRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNewArrayRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromStrictNewArrayRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictParamRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromStrictParamRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedCallRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromStrictTypedCallRector.php',
        'Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedPropertyRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/ClassMethod/ReturnTypeFromStrictTypedPropertyRector.php',
        'Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector' => 'vendor/rector/rector/rules/TypeDeclaration/Rector/Closure/ClosureReturnTypeRector.php',
    ];

    private const int TIMEOUT = 900;

    public function __construct(
        private readonly string $root,
        private readonly Closure $environment,
    ) {}

    public function label(): string
    {
        return 'files where Rector added types it could prove';
    }

    /**
     * Every rule for these rulesets in one Rector run over every flagged file.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $paths
     * @return list<string>|null The files Rector changed, or null when it could not run.
     */
    public function apply(array $keys, array $paths): ?array
    {
        $rules = array_values(array_unique(array_merge(...array_map($this->available(...), $keys))));
        $paths = array_values(array_filter(array_unique($paths), fn (string $path): bool => is_file($this->root.'/'.$path)));

        if ($rules === [] || $paths === [] || ! is_file($this->root.'/vendor/bin/rector')) {
            return null;
        }

        $config = sys_get_temp_dir().'/studio-rector-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($config, $this->config($rules));
        $process = new Process(['vendor/bin/rector', 'process', ...$paths, '--config='.$config, '--output-format=json', '--no-progress-bar'], $this->root, ($this->environment)(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($config);
        }

        $start = strpos($process->getOutput(), '{');
        $json = $start === false ? null : json_decode(substr($process->getOutput(), $start), true);

        return is_array($json) ? array_values(array_map(fn (array $diff): string => (string) ($diff['file'] ?? ''), (array) ($json['file_diffs'] ?? []))) : null;
    }

    /**
     * @return list<string>
     */
    private function available(string $key): array
    {
        return array_keys(array_filter(self::RULES[$key] ?? [], fn (string $file): bool => is_file($this->root.'/'.$file)));
    }

    /**
     * @param  list<string>  $rules
     */
    private function config(array $rules): string
    {
        $listed = implode(",\n        ", array_map(fn (string $rule): string => '\\'.$rule.'::class', $rules));

        return <<<PHP
            <?php

            return \\Rector\\Config\\RectorConfig::configure()
                ->withRules([
                    {$listed},
                ]);
            PHP;
    }
}
