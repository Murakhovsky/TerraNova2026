<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectEvidenceAuthorization.php';
require $root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectEvidencePlanner.php';

use App\Engineering\Application\Service\EngineeringArchitectEvidencePlanner;

$planner = new EngineeringArchitectEvidencePlanner();
$budget = $planner->budget(0, 0, ['src/A.php']);
if ($budget['rounds_remaining'] !== 4 || $budget['files_remaining'] !== 12) {
    throw new RuntimeException('Initial evidence budget is not bounded.');
}
$partial = $planner->plan(['src/B.php','src/C.php','src/B.php'], ['src/A.php'], 10, 2);
if ($partial['status'] !== 'COLLECT' || $partial['paths'] !== ['src/B.php','src/C.php']
    || count($partial['rejected']) !== 1 || !$partial['partial']) {
    throw new RuntimeException('Valid files should survive rejected sibling paths.');
}
foreach ($partial['rejected'] as $rejection) {
    if (isset($rejection['path']) || strlen($rejection['path_sha256']) !== 64) {
        throw new RuntimeException('Rejected paths must not be exposed in audit logs.');
    }
}
foreach ([[12,2,'FILE_LIMIT'],[4,4,'ROUND_LIMIT']] as [$used,$round,$reason]) {
    $outcome = $planner->plan(['src/D.php'], ['src/A.php'], $used, $round);
    if ($outcome['status'] !== 'EXHAUSTED' || $outcome['reason'] !== $reason) {
        throw new RuntimeException('Expected typed resource budget outcome '.$reason);
    }
}
$invalid = $planner->plan(['src/key.pem'], ['src/A.php'], 0, 0);
if ($invalid['status'] !== 'REJECTED' || $invalid['reason'] !== 'NO_USABLE_PATHS') {
    throw new RuntimeException('Unsafe-only request must stop without further LLM attempts.');
}
$withMissing = $planner->plan(
    ['src/B.php', 'src/C.php'], ['src/A.php'], 0, 0,
    static fn (array $paths): array => ['src/B.php'],
);
if ($withMissing['status'] !== 'COLLECT' || $withMissing['paths'] !== ['src/B.php']
    || ($withMissing['rejected'][0]['reason'] ?? null) !== 'NOT_FOUND_AT_PINNED_REVISION') {
    throw new RuntimeException('Missing repository file must not cancel valid sibling evidence.');
}
$allMissing = $planner->plan(
    ['src/C.php'], ['src/A.php'], 0, 0,
    static fn (array $paths): array => [],
);
if ($allMissing['status'] !== 'REJECTED' || $allMissing['reason'] !== 'NO_USABLE_PATHS') {
    throw new RuntimeException('All missing files must produce typed technical blocker.');
}

echo "Engineering evidence planner V2 passed.\n";
