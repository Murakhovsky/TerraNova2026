<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/symfony/src/Engineering/Application/Context/RepositoryFileReaderInterface.php';
require $root.'/symfony/src/Engineering/Application/Context/EngineeringStandardsProvider.php';
require $root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectContextOptimizer.php';

use App\Engineering\Application\Context\EngineeringStandardsProvider;
use App\Engineering\Application\Context\RepositoryFileReaderInterface;
use App\Engineering\Application\Service\EngineeringArchitectContextOptimizer;

$reader = new class implements RepositoryFileReaderInterface {
    /** @var list<string> */
    public array $requested = [];
    public function readMany(array $paths): array
    {
        $this->requested = $paths;
        return array_map(static fn (string $path): array => [
            'path' => $path, 'content' => 'standard', 'complete' => true,
            'size' => 8, 'sha256' => hash('sha256', 'standard'),
        ], $paths);
    }
};
$standards = new EngineeringStandardsProvider($reader);
$full = $standards->all();
if (count($full) !== 8) throw new RuntimeException('Canonical standards must remain available for other roles.');
$subset = $standards->forArchitect();
if (count($subset) !== 6 || count(array_intersect(array_column($full, 'path'), array_column($subset, 'path'))) !== 6) {
    throw new RuntimeException('Architect standards subset must be a strict subset of all canonical standards.');
}
foreach (['architecture-standard.md', 'security-standard.md', 'agent-permissions.md'] as $required) {
    if (!array_filter($subset, static fn (array $standard): bool => str_ends_with($standard['path'], $required))) {
        throw new RuntimeException('Architect critical standard omitted: '.$required);
    }
}
foreach (['coding-standard.md', 'learning-loop.md'] as $excluded) {
    if (array_filter($subset, static fn (array $standard): bool => str_ends_with($standard['path'], $excluded))) {
        throw new RuntimeException('Architect loaded an irrelevant standard: '.$excluded);
    }
}

$optimizer = new EngineeringArchitectContextOptimizer();
$revision = str_repeat('a', 40);
$fullPatch = str_repeat('A', 12000);
$largePatch = str_repeat('B', 19000);
$files = [
    ['path' => 'src/covered.php', 'content' => $fullPatch, 'complete' => true, 'size' => strlen($fullPatch)],
    ['path' => 'src/partial.php', 'content' => 'partial', 'complete' => false, 'size' => 19000],
];
$diff = [
    'base_revision' => str_repeat('b', 40),
    'head_revision' => $revision,
    'status' => 'ahead',
    'files' => [
        ['path' => 'src/covered.php', 'status' => 'modified', 'additions' => 10, 'deletions' => 2, 'patch' => $fullPatch],
        ['path' => 'src/partial.php', 'status' => 'modified', 'additions' => 8, 'deletions' => 1, 'patch' => $largePatch],
    ],
];
for ($i = 0; $i < 35; ++$i) {
    $diff['files'][] = ['path' => "src/file-$i.php", 'status' => 'added', 'additions' => 100, 'deletions' => 0, 'patch' => $largePatch];
}
$optimized = $optimizer->compactRevisionDiff($diff, $files);
if ($optimized === null || $optimized['head_revision'] !== $revision
    || count($optimized['files']) !== count($diff['files'])) {
    throw new RuntimeException('Architect diff compaction must preserve all revision and file metadata.');
}
if (isset($optimized['files'][0]['patch']) || ($optimized['files'][0]['patch_source'] ?? null) !== 'complete_repository_file') {
    throw new RuntimeException('Patch already supplied as a complete repository file was duplicated.');
}
if (!isset($optimized['files'][1]['patch']) || ($optimized['files'][1]['patch_truncated'] ?? false) !== true
    || $optimized['files'][1]['additions'] !== 8) {
    throw new RuntimeException('Uncovered diff must retain bounded patch excerpt and change metadata.');
}
if (($optimized['compaction']['patch_preview_bytes_used'] ?? 0) > EngineeringArchitectContextOptimizer::MAX_PATCH_BYTES_TOTAL) {
    throw new RuntimeException('Architect diff excerpt exceeded global budget.');
}
foreach ($optimized['files'] as $entry) {
    if (strlen((string) ($entry['patch'] ?? '')) > EngineeringArchitectContextOptimizer::MAX_PATCH_BYTES_PER_FILE) {
        throw new RuntimeException('Architect diff excerpt exceeded per-file budget.');
    }
}
if ($optimizer->compactRevisionDiff(null, $files) !== null || $diff['files'][0]['patch'] !== $fullPatch) {
    throw new RuntimeException('Architect compaction must not mutate source data.');
}
$components = $optimizer->serializedBytesByComponent([
    'feature_spec' => ['requirements' => 'Обов’язкові'],
    'repository_diff' => $optimized,
]);
if (($components['feature_spec'] ?? 0) <= 0 || ($components['repository_diff'] ?? 0) <= 0) {
    throw new RuntimeException('Architect context size accounting must include each component.');
}

echo "Engineering Architect context optimization passed.\n";
