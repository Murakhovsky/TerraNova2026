<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/symfony/src/Engineering/Application/Service/EngineeringArchitectEvidenceAuthorization.php';

use App\Engineering\Application\Service\EngineeringArchitectEvidenceAuthorization;

$policy = new EngineeringArchitectEvidenceAuthorization();
$first = $policy->authorize(
    ['symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php', 'tests/unit/engineering_agent_contracts.php'],
    ['docs/03-architecture/domain-map.md'],
);
if (count($first) !== 2) throw new RuntimeException('Valid read-only repository evidence was denied.');

$invalid = [
    [[], []],
    [['docs/03-architecture/domain-map.md'], ['docs/03-architecture/domain-map.md']],
    [['../.env'], []],
    [['.env'], []],
    [['symfony/config/secrets/prod.yaml'], []],
    [['user-data/customer-records.json'], []],
    [['symfony/var/logs.txt'], []],
    [['symfony/src/SomeCredential.php'], []],
    [['symfony/src/key.pem'], []],
    [['symfony\\src\\File.php'], []],
    [['tests//unit/test.php'], []],
    [['docs/a.md', 'docs/a.md'], []],
    [array_fill(0, 7, 'docs/03-architecture/domain-map.md'), []],
];
foreach ($invalid as [$paths, $already]) {
    try {
        $policy->authorize($paths, $already);
        throw new RuntimeException('Unsafe or duplicate evidence request was auto-authorized: '.json_encode($paths));
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'Unsafe or duplicate evidence request was auto-authorized')) throw $e;
    }
}
$legacyEvidenceRequest = [
    'type' => 'WORKFLOW_EVIDENCE_REFRESH',
    'question' => 'Should this workflow obtain missing read-only repository evidence and rerun?',
    'reason' => 'Repository access is configured; more source evidence is required.',
    'options' => [
        ['id' => 'REFRESH_EVIDENCE', 'description' => 'Read sources at 77a578fcc8e5338b4a530242349d258bf73e0de5.'],
        ['id' => 'CANCEL', 'description' => 'Cancel feature.'],
    ],
    'recommended_option' => 'REFRESH_EVIDENCE',
];
if (!$policy->isLegacyReadOnlyRefresh($legacyEvidenceRequest)) {
    throw new RuntimeException('Legacy read-only evidence request incorrectly classified as human decision.');
}
if ($policy->requestedLegacyRevision($legacyEvidenceRequest) !== '77a578fcc8e5338b4a530242349d258bf73e0de5') {
    throw new RuntimeException('Legacy evidence revision hint was not extracted.');
}
$legacyPaths = $policy->legacyRefreshPaths(['symfony/config/routes.yaml']);
if (count($legacyPaths) !== 6 || in_array('symfony/config/routes.yaml', $legacyPaths, true)) {
    throw new RuntimeException('Legacy evidence paths are not bounded or exclude previously supplied files.');
}
foreach ([
    ['type' => 'CREDENTIAL_PERMISSION', 'recommended_option' => 'REFRESH_EVIDENCE'],
    array_replace($legacyEvidenceRequest, ['type' => 'SCOPE_CHANGE']),
    array_replace($legacyEvidenceRequest, ['recommended_option' => 'CANCEL']),
    array_replace($legacyEvidenceRequest, ['options' => [['id' => 'REFRESH_EVIDENCE']]]),
] as $badGate) {
    if ($policy->isLegacyReadOnlyRefresh($badGate)) {
        throw new RuntimeException('Actual human decision was incorrectly auto-classified.');
    }
}

if (EngineeringArchitectEvidenceAuthorization::MAX_ROUNDS !== 2) {
    throw new RuntimeException('Architect evidence rerun limit was accidentally changed.');
}

echo "Engineering Architect read-only evidence authorization passed.\n";
