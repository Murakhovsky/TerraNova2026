<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$command = 'php ' . escapeshellarg($root . '/docs/.vitepress/generate-runtime-evidence.php');
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
$assert($exitCode === 0, 'Runtime evidence catalogue generator failed.');

$catalogue = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
$assert(($catalogue['schema_version'] ?? null) === 1, 'Runtime evidence catalogue schema must be v1.');
$entries = is_array($catalogue['entries'] ?? null) ? $catalogue['entries'] : [];
$assert($entries !== [], 'Runtime evidence catalogue must not be empty.');

$find = static function (string $type, string $ref) use ($entries): ?array {
    foreach ($entries as $entry) {
        if (($entry['type'] ?? null) === $type && ($entry['ref'] ?? null) === $ref) {
            return $entry;
        }
    }
    return null;
};

$useCase = $find('use_case', 'AssignDealOwner');
$assert($useCase !== null, 'Source evidence lost Sales AssignDealOwner use case.');
$assert(($useCase['strength'] ?? null) === 'source', 'Use-case evidence must be source-strength.');

$event = $find('event', 'sales.deal.won');
$assert($event !== null, 'Runtime evidence lost sales.deal.won event.');
$assert(($event['strength'] ?? null) === 'runtime', 'Canonical event evidence must be runtime-strength.');

$contract = $find('contract', 'Domains\\Property\\Contract\\PropertyReferencePort');
$assert($contract !== null, 'Runtime evidence lost Sales → Property contract bridge.');
$assert(($contract['strength'] ?? null) === 'runtime', 'Canonical cross-domain contract evidence must be runtime-strength.');

$capability = $find('capability', 'property.inventory');
$assert($capability !== null, 'Current-checkout evidence lost Property inventory capability.');
$assert(($capability['domain'] ?? null) === 'property', 'Capability evidence must retain Domain ownership.');

$definitions = glob($root . '/resources/processes/*.json') ?: [];
$assert($definitions !== [], 'Canonical Process Registry resources are missing.');
foreach ($definitions as $path) {
    $definition = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $assert(($definition['schema_version'] ?? 0) >= 3, basename($path) . ' must preserve Process Registry schema v3+ semantics.');
    $assert(in_array($definition['state'] ?? null, ['as-is', 'to-be'], true), basename($path) . ' has invalid business state.');
    $assert(!array_key_exists('verification', $definition), basename($path) . ' must not author verification.');
}

$checker = (string)file_get_contents($root . '/docs/.vitepress/check-processes.mjs');
$assert(!str_contains($checker, 'docs/12-reference'), 'Process verifier must not treat generated Markdown as runtime evidence.');
$assert(str_contains($checker, "new Set(['as-is', 'to-be'])"), 'Process verifier must keep business state separate from verification.');
$assert(str_contains($checker, 'processVerification'), 'Process verifier must derive evidence status.');
$assert(str_contains($checker, 'PROCESS_REGISTRY_ROOT'), 'Process verifier must consume the platform Process Registry authority.');

$checkOutput = [];
$checkExit = 0;
exec('node ' . escapeshellarg($root . '/docs/.vitepress/check-processes.mjs') . ' 2>&1', $checkOutput, $checkExit);
$assert($checkExit === 0, "Process evidence checks failed:\n" . implode("\n", $checkOutput));

echo "Visualization V0.5 process runtime evidence contract passed.\n";
