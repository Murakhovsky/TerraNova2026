<?php
declare(strict_types=1);

/**
 * P0 DESIGN-PACK BASELINE ONLY.
 * Checks inventory and test anchors, not execution parity nor DB migration.
 * Included automatically in bin/verify contract (tests/architecture/*.php).
 */
$root = dirname(__DIR__, 2);
$registryFile = $root . '/resources/architecture/cos-execution-duplication-registry.json';
$casesFile = $root . '/resources/architecture/cos-execution-parity-cases.json';
$mustExist = [
    $root . '/docs/03-architecture/cos-execution-contract-consolidation-p0.md',
    $root . '/docs/03-architecture/cos-execution-state-migration-map.md',
];
foreach ([$registryFile, $casesFile, ...$mustExist] as $path) {
    if (!is_file($path)) throw new RuntimeException('P0 Contract Pack missing file: ' . $path);
}
$registry = json_decode((string) file_get_contents($registryFile), true, 512, JSON_THROW_ON_ERROR);
$cases = json_decode((string) file_get_contents($casesFile), true, 512, JSON_THROW_ON_ERROR);
foreach ([$registry, $cases] as $payload) {
    if (!is_array($payload) || ($payload['schema_version'] ?? null) !== '1.0.0'
        || ($payload['package_id'] ?? null) !== 'COS-FED-EXEC-CONTRACT-CONSOLIDATION-P0') {
        throw new RuntimeException('Malformed or mismatched consolidation registry.');
    }
}
if (count($registry['entries'] ?? []) < 18 || count($cases['tests'] ?? []) < 20) {
    throw new RuntimeException('Consolidation inventory/parity catalogue unexpectedly incomplete.');
}
$seen = [];
$allowed = $registry['allowed_decisions'];
$sourceGroups = ['Kernel' => false, 'Federation' => false, 'Engineering' => false];
foreach ($registry['entries'] as $item) {
    $id = $item['id'] ?? null;
    if (!is_string($id) || isset($seen[$id])) {
        throw new RuntimeException('Duplicate/invalid consolidation entry id.');
    }
    $seen[$id] = true;
    if (!in_array($item['decision'] ?? null, $allowed, true)
        || !in_array($item['migration_status'] ?? null, ['NOT_STARTED','IN_PROGRESS','VERIFIED','RETIRED'], true)) {
        throw new RuntimeException('Consolidation entry has unsupported decision/status: ' . $id);
    }
    // IN_PROGRESS means inspected implementation work exists, NOT that
    // every runtime consumer was migrated or production acceptance passed.
    $migrationState=$item['migration_status'];
    if ($migrationState==='IN_PROGRESS') {
        $evidence=$item['implementation_evidence']??null;
        if (!is_array($evidence)
            || !preg_match('/^[a-f0-9]{40}$/D',(string)($evidence['commit_sha']??''))
            || ($evidence['acceptance']??null)!=='PENDING_LATEST_CI_AND_FLEET_PARITY') {
            throw new RuntimeException('Partial migration requires an explicit unaccepted implementation trail: '.$id);
        }
        foreach (['source_port','technical_writer','domain_evidence','mysql_regression'] as $field) {
            if (!is_string($evidence[$field]??null)
                || str_contains($evidence[$field],'..')
                || !is_file($root.'/'.$evidence[$field])) {
                throw new RuntimeException('Missing in-progress implementation source evidence: '.$id.':'.$field);
            }
        }
    }
    if (in_array($migrationState,['VERIFIED','RETIRED'],true)
        && ($item['runtime_consumers_verified']??null)!==true) {
        throw new RuntimeException('Completed migration requires exhaustive verified consumers: '.$id);
    }
    foreach (['implementation', 'known_consumer'] as $source) {
        $path = $item[$source]['path'] ?? null;
        if (!is_string($path) || str_contains($path, '..') || !is_file($root . '/' . $path)) {
            throw new RuntimeException('Missing source evidence: ' . $id . ':' . (string) $path);
        }
        foreach (array_keys($sourceGroups) as $group) {
            if (str_contains($path, '/' . $group . '/') || str_contains($path, '\\' . $group . '\\')
                || ($group === 'Kernel' && str_starts_with($path, 'app/Kernel/'))
                || ($group === 'Federation' && str_contains($path, 'Federation'))
                || ($group === 'Engineering' && str_contains($path, 'Engineering'))) {
                $sourceGroups[$group] = true;
            }
        }
    }
    if (!is_file($root . '/' . ($item['baseline_test'] ?? ''))) {
        throw new RuntimeException('Missing baseline anchor: ' . $id);
    }
}
foreach ($sourceGroups as $group => $found) {
    if (!$found) throw new RuntimeException('Missing runtime group in inventory: ' . $group);
}
$testIds = [];
foreach ($cases['tests'] as $test) {
    $id = $test['id'] ?? null;
    if (!is_string($id) || isset($testIds[$id])) throw new RuntimeException('Duplicate/invalid parity case ID.');
    $testIds[$id] = true;
    $status=$test['target_status']??null;
    if (!in_array($status,['NOT_IMPLEMENTED','IN_PROGRESS'],true)) {
        // Final acceptance is exclusively a runtime/CI/operator gate,
        // never a green light from this static inventory.
        throw new RuntimeException('Parity cannot self-certify VERIFIED status: '.$id);
    }
    if ($status==='IN_PROGRESS') {
        $runner=$test['target_test']??null;
        if (!is_string($runner) || str_contains($runner,'..')
            || !str_starts_with($runner,'tests/')
            || !is_file($root.'/'.$runner)
            || ($test['target_evidence_status']??null)!=='RUNNER_PRESENT_NOT_FULL_CASE_ACCEPTANCE'
            || trim((string)($test['implementation_note']??''))==='') {
            throw new RuntimeException('Partial parity work has no grounded target runner: '.$id);
        }
    }
    if (!is_file($root . '/' . ($test['baseline_anchor'] ?? ''))) {
        throw new RuntimeException('Missing parity baseline anchor: ' . $id);
    }
}
$activeMigrations=count(array_filter($registry['entries'],static fn(array $e):bool=>($e['migration_status']??null)==='IN_PROGRESS'));
$activeParity=count(array_filter($cases['tests'],static fn(array $t):bool=>($t['target_status']??null)==='IN_PROGRESS'));
echo sprintf(
    "Execution consolidation P0 inventory passed: %d concerns (%d unaccepted increments); %d parity cases (%d partial runners; no full acceptance).\n",
    count($seen),$activeMigrations,count($testIds),$activeParity
);
