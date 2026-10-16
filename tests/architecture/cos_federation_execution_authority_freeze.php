<?php
declare(strict_types=1);

/**
 * Stage 0: fail CI when a NEW technical Run/Step writer or generic Federation
 * executor is introduced. Existing debt is grandfathered for controlled
 * migration, not certified as compliant with single execution authority.
 *
 * Static source audit only; does not prove live tenant, SQL, worker or parity.
 */
$root = dirname(__DIR__, 2);
$file = $root . '/resources/architecture/cos-execution-authority-freeze.json';
$policyFile = $root . '/docs/03-architecture/federation-governance-policy.json';
$registry = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$policy = json_decode((string) file_get_contents($policyFile), true, 512, JSON_THROW_ON_ERROR);
if (($registry['schema_version'] ?? null) !== '1.0.0'
    || ($registry['status'] ?? null) !== 'ACTIVE_FREEZE__CANONICAL_CUTOVER_NOT_COMPLETE') {
    throw new RuntimeException('Execution authority freeze registry is missing or falsely marked complete.');
}
$rules = array_column($policy['rules'] ?? [], 'id');
if (!in_array('FED-INV-04', $rules, true)
    || ($registry['governance_rule'] ?? null) !== 'FED-INV-04') {
    throw new RuntimeException('Single execution authority governance rule is not active.');
}

$allowed = $registry['allowed_writers'] ?? null;
$tables = [
    'cos_federation_runs', 'cos_federation_steps',
    'cos_workflow_runs', 'cos_workflow_steps',
];
if (!is_array($allowed) || array_keys($allowed) !== $tables) {
    throw new RuntimeException('Execution authority registry must enumerate exactly four Run/Step tables.');
}
$permitted = [];
foreach ($allowed as $table => $owners) {
    if (!is_array($owners) || $owners === [] || count($owners) !== count(array_unique($owners))) {
        throw new RuntimeException('Execution writer owners are empty or duplicated: ' . $table);
    }
    foreach ($owners as $path) {
        if (!is_string($path) || str_contains($path, '..') || !is_file($root . '/' . $path)) {
            throw new RuntimeException('Invalid/stale approved execution writer path: ' . (string) $path);
        }
        $permitted[$table][$path] = true;
    }
}
$existingExecutors = array_fill_keys($registry['grandfathered_legacy_executors'] ?? [], true);
foreach ($existingExecutors as $path => $_) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Legacy executor inventory drift: ' . $path);
    }
}

/* Only this transactional, rolled-back MySQL smoke command is excluded;
 * do not allow an arbitrary registry waiver for a production source file. */
$fixture = 'symfony/src/Command/FederationPersistenceSmokeCommand.php';
if (($registry['nonproduction_fixture_exclusions'] ?? []) !== [$fixture]
    || !is_file($root . '/' . $fixture)) {
    throw new RuntimeException('Fixture exemption inventory changed without review.');
}

$actual = [];
$inspected = 0;
$roots = ['app', 'symfony/src', 'bin'];
$sqlDml = '~\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+(cos_(?:federation|workflow)_(?:runs|steps))\b~i';
$dbalDml = "~->\s*(?:insert|update|delete)\s*\(\s*['\"](cos_(?:federation|workflow)_(?:runs|steps))['\"]~i";
foreach ($roots as $relativeRoot) {
    $directory = new RecursiveDirectoryIterator($root . '/' . $relativeRoot, FilesystemIterator::SKIP_DOTS);
    foreach (new RecursiveIteratorIterator($directory) as $entry) {
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
            continue;
        }
        $path = substr($entry->getPathname(), strlen($root) + 1);
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $content = (string) file_get_contents($entry->getPathname());
        ++$inspected;
        if ($path === $fixture) continue; // Synthetic transaction smoke, not a production Run/Step writer.
        // A business Domain or Goal application must not gain a new generic
        // Federation engine. Existing two legacy adapters remain frozen.
        if (preg_match('~/(?:Federation|Goal)[^/]*(?:WorkflowEngine|WorkflowRunner|Orchestrator|Scheduler|QueueWorker|EventBus)\.php$~', $path)
            && !isset($existingExecutors[$path])) {
            throw new RuntimeException('New parallel Federation execution engine forbidden: ' . $path);
        }
        $seenTables = [];
        if (preg_match_all($sqlDml, $content, $matches)) {
            foreach ($matches[1] as $table) $seenTables[strtolower($table)] = true;
        }
        if (preg_match_all($dbalDml, $content, $matches)) {
            foreach ($matches[1] as $table) $seenTables[strtolower($table)] = true;
        }
        foreach ($seenTables as $table => $_) {
            $actual[$table][$path] = true;
            if (!isset($permitted[$table][$path])) {
                throw new RuntimeException('New parallel Run/Step writer forbidden: ' . $table . ' in ' . $path);
            }
        }
    }
}
foreach ($permitted as $table => $owners) {
    foreach ($owners as $path => $_) {
        if (!isset($actual[$table][$path])) {
            throw new RuntimeException('Writer inventory is stale and needs explicit architecture review: ' . $table . ':' . $path);
        }
    }
}
printf(
    "COS Federation execution authority freeze passed: %d PHP files scanned; %d grandfathered writer relationships; single-writer cutover NOT proven.\n",
    $inspected,
    array_sum(array_map('count', $permitted))
);
