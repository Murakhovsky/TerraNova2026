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
if (EngineeringArchitectEvidenceAuthorization::MAX_ROUNDS !== 2) {
    throw new RuntimeException('Architect evidence rerun limit was accidentally changed.');
}

echo "Engineering Architect read-only evidence authorization passed.\n";
