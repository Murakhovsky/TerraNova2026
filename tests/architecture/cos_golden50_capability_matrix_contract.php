<?php
declare(strict_types=1);

/**
 * Non-executing Golden 50 Capability Operability catalog guard.
 * A green result certifies source metadata only. NEVER prints runtime PASS.
 */
$root = dirname(__DIR__, 2);
$fail = static function (string $why): never { throw new RuntimeException($why); };
$check = static function (bool $ok, string $why) use ($fail): void {
    if (!$ok) $fail($why);
};
$matrixPath = $root . '/resources/testing/golden50/capability-operability-matrix.v1.json';
$matrix = json_decode((string) file_get_contents($matrixPath), true, 512, JSON_THROW_ON_ERROR);
$check(is_array($matrix), 'Expected JSON object matrix.');
$check(($matrix['standard'] ?? null) === 'COS-G50-CAPABILITY-OPERABILITY-1.0', 'Unversioned matrix.');
$check(($matrix['schema_version'] ?? null) === '1.0.0', 'Unexpected matrix schema revision.');
$check(($matrix['execution_mode'] ?? null) === 'template_applied_per_capability_and_initiator', 'Matrix must be dynamic per capability.');
$allowed = ['catalog', 'contract', 'binding', 'initiator', 'security', 'workflow', 'reliability', 'outcome', 'events', 'release'];
$cases = $matrix['cases'] ?? null;
$check(is_array($cases) && array_is_list($cases) && count($cases) === 50, 'Exactly 50 canonical operability tests required.');
$counts = array_fill_keys($allowed, 0);
$ids = [];
foreach ($cases as $index => $case) {
    $check(is_array($case), 'Invalid test case object.');
    $id = sprintf('CO-%02d', $index + 1);
    $check(($case['id'] ?? null) === $id, 'Missing/out-of-order test case: ' . $id);
    $check(!isset($ids[$id]), 'Duplicate case ' . $id);
    $ids[$id] = true;
    $group = $case['group'] ?? '';
    $check(is_string($group) && array_key_exists($group, $counts), 'Unexpected group for ' . $id);
    $counts[$group]++;
    foreach (['title', 'procedure', 'expected', 'evidence'] as $field) {
        $check(is_string($case[$field] ?? null) && strlen(trim($case[$field])) >= 8, 'Weak ' . $field . ' for ' . $id);
    }
    $check(($case['classification'] ?? null) === 'required_or_explicitly_not_applicable', 'Unbounded applicability for ' . $id);
    $check(($case['status'] ?? null) === 'not_run', 'Source test matrix must never pretend to be executed: ' . $id);
}
foreach ($counts as $group => $count) {
    $check($count === 5, 'Expected five scenarios in group ' . $group);
}
$check(
    ($matrix['allowed_results'] ?? null) === ['PASS', 'FAIL', 'BLOCKED', 'NOT_APPLICABLE_WITH_EVIDENCE'],
    'Missing four explicit outcome types.'
);

/* Baseline is minimum regression set; future additions are discovered, not prohibited. */
$baseline = [
    'property' => ['property.bulk.create', 'property.asset.create', 'property.asset.get', 'property.asset.update', 'property.asset.search'],
    'growth' => ['growth.handoff.prepare', 'growth.handoff.target.sales', 'growth.market.discovery', 'growth.candidate.qualify'],
    'sales' => ['sales.create_task', 'sales.deal.won.observation'],
    'documents' => ['documents.proposal.prepare', 'documents.signature.sign', 'documents.signature.request'],
    'capital_markets' => ['capital_markets.research.result.record'],
    'federation' => ['federation.plan.approval'],
];
$found = [];
$ownerCounts = [];
foreach (glob($root . '/app/Domains/*/module.php') ?: [] as $manifestPath) {
    $manifest = require $manifestPath;
    $check(is_array($manifest), 'Invalid Domain manifest: ' . $manifestPath);
    $owner = (string) ($manifest['id'] ?? '');
    $contributions = $manifest['contributions'] ?? [];
    $declared = $contributions['capabilities'] ?? [];
    $typed = $contributions['capability_contracts'] ?? [];
    $check(is_array($declared) && is_array($typed), 'Malformed capability list in ' . $owner);
    $ownerCounts[$owner] = 0;
    foreach ($typed as $contract) {
        $check(is_array($contract), 'Malformed typed contract in ' . $owner);
        $id = (string) ($contract['id'] ?? '');
        $check((bool) preg_match('/^[a-z][a-z0-9_]*(?:\\.[a-z][a-z0-9_]*)+$/D', $id), 'Invalid capability ID: ' . $id);
        $check(($contract['owner_domain'] ?? null) === $owner, 'Incorrect owner for ' . $id);
        $check(in_array($id, $declared, true), 'Typed contract missing in declared list: ' . $id);
        $check(!isset($found[$id]), 'Duplicate capability ID: ' . $id);
        $check((bool) preg_match('/^\\d+\\.\\d+\\.\\d+$/', (string) ($contract['version'] ?? '')), 'Unversioned capability: ' . $id);
        $check(in_array(($contract['kind'] ?? ''), ['query', 'command', 'job', 'workflow', 'agent_tool'], true), 'Invalid capability kind: ' . $id);
        $check(is_string($contract['execution_binding'] ?? null) && str_contains($contract['execution_binding'], ':'), 'Missing binding descriptor: ' . $id);
        foreach (['input_schema', 'output_schema'] as $schemaField) {
            $relative = (string) ($contract[$schemaField] ?? '');
            $check(str_starts_with($relative, 'resources/contracts/') && !str_contains($relative, '..'), 'Unsafe schema path for ' . $id);
            $absolute = $root . '/' . $relative;
            $check(is_file($absolute), 'Missing schema ' . $relative);
            $schema = json_decode((string) file_get_contents($absolute), true, 512, JSON_THROW_ON_ERROR);
            $check(is_array($schema) && isset($schema['type']), 'Invalid JSON schema ' . $relative);
        }
        $found[$id] = $owner;
        $ownerCounts[$owner]++;
    }
}
foreach ($baseline as $owner => $capabilities) {
    foreach ($capabilities as $id) {
        $check(($found[$id] ?? null) === $owner, 'Baseline regression / missing typed capability: ' . $id);
    }
}
ksort($ownerCounts);
echo 'Golden 50 capability matrix source contract PASS (STATIC ONLY; no runtime or business acceptance).'
    . PHP_EOL;
echo 'Typed contracts enumerated: ' . count($found) . '; case templates: ' . count($cases)
    . '; Domains: ' . json_encode($ownerCounts, JSON_UNESCAPED_SLASHES) . PHP_EOL;
