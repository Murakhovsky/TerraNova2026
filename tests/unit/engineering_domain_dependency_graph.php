<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use InvalidArgumentException;
use RuntimeException;

$graph = new FeatureDependencyGraph();
$definition = [
    ['key' => 'money'],
    ['key' => 'instrument'],
    ['key' => 'market-data'],
    ['key' => 'execution'],
];
$dependencies = [
    ['feature_key' => 'instrument', 'depends_on_key' => 'money', 'type' => 'REQUIRES'],
    ['feature_key' => 'market-data', 'depends_on_key' => 'instrument', 'type' => 'REQUIRES'],
    ['feature_key' => 'execution', 'depends_on_key' => 'market-data', 'type' => 'REQUIRES'],
];
$graph->assertValid($definition, $dependencies);

$runtime = [
    ['feature_key' => 'money', 'status' => 'COMPLETED'],
    ['feature_key' => 'instrument', 'status' => 'NOT_STARTED'],
    ['feature_key' => 'market-data', 'status' => 'NOT_STARTED'],
    ['feature_key' => 'execution', 'status' => 'NOT_STARTED'],
];
if ($graph->ready($runtime, $dependencies) !== ['instrument']) {
    throw new RuntimeException('Dependency scheduler did not unlock only the next eligible feature.');
}

try {
    $graph->assertValid(
        [['key' => 'a'], ['key' => 'b']],
        [
            ['feature_key' => 'a', 'depends_on_key' => 'b', 'type' => 'REQUIRES'],
            ['feature_key' => 'b', 'depends_on_key' => 'a', 'type' => 'REQUIRES'],
        ],
    );
    throw new RuntimeException('Cyclic Domain feature graph was accepted.');
} catch (InvalidArgumentException) {
}

try {
    $graph->assertValid(
        [['key' => 'a']],
        [['feature_key' => 'a', 'depends_on_key' => 'missing', 'type' => 'REQUIRES']],
    );
    throw new RuntimeException('Unknown Domain feature dependency was accepted.');
} catch (InvalidArgumentException) {
}

echo "Engineering Domain dependency graph passed.\n";
