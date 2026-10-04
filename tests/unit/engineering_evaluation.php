<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Evaluation\EngineeringEvaluationRunner;

$dataset = json_decode(
    (string) file_get_contents(dirname(__DIR__).'/fixtures/engineering/evaluation/v0.1.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$managerCases = array_values(array_filter(
    $dataset['cases'] ?? [],
    static fn (array $case): bool => ($case['role'] ?? null) === 'ENGINEERING_MANAGER',
));
if (count($managerCases) < 10) throw new RuntimeException('Engineering Manager evaluation set is too small.');

$result = (new EngineeringEvaluationRunner())->run($dataset);

if ($result['cases'] < 18) throw new RuntimeException('Engineering evaluation set is too small.');
if ($result['failed'] !== 0) {
    throw new RuntimeException('Engineering evaluation regression: '.json_encode($result['results'], JSON_THROW_ON_ERROR));
}
if ($result['accuracy'] !== 1.0) throw new RuntimeException('Engineering evaluation accuracy must be 1.0 for deterministic validation cases.');

echo "Engineering evaluation dataset passed: ".$result['cases']." cases.\n";
