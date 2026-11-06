<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/symfony/src/Engineering/Application/Service/EngineeringAutopilotAdmissionPolicy.php';

use App\Engineering\Application\Service\EngineeringAutopilotAdmissionPolicy;

$policy = new EngineeringAutopilotAdmissionPolicy();
$fixture = [
    'number' => 123,
    'state' => 'open',
    'is_pull_request' => false,
    'title' => 'Low-risk contract test',
    'labels' => ['P2', 'cos:autopilot:ready', 'cos:autopilot:low-risk'],
    'body' => "## Acceptance Criteria\n- [ ] Unit acceptance is green\n\n"
        ."## Allowed Paths\n- \x60tests/unit/autopilot_spec.php\x60\n\n## Dependencies\nNone\n",
];

$admit = static function (array $issue, ?callable $deps = null) use ($policy): array {
    return $policy->evaluate($issue, $deps ?? static fn (int $number): bool => false);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert($admit($fixture)['admitted'], 'Valid bounded issue must be admitted.');
$assert($admit(array_replace($fixture, ['labels' => ['P2', 'cos:autopilot:ready']]))['admitted'] === false,
    'Missing explicit low-risk label must fail.');
$assert($admit(array_replace($fixture, ['labels' => [...$fixture['labels'], 'P0']]))['admitted'] === false,
    'P0 requires direct supervision.');
$assert($admit(array_replace($fixture, ['labels' => [...$fixture['labels'], 'risk:high']]))['admitted'] === false,
    'High-risk label must block.');
$assert($admit(array_replace($fixture, ['body' => str_replace('tests/unit/autopilot_spec.php',
    '.github/workflows/evil.yml', $fixture['body'])]))['admitted'] === false,
    'Workflow mutation is not an autopilot low-risk path.');
$assert($admit(array_replace($fixture, ['body' => str_replace('## Acceptance Criteria',
    '## Not Acceptance', $fixture['body'])]))['admitted'] === false,
    'Acceptance criteria must be explicit.');
$dependent = array_replace($fixture, ['body' => str_replace('## Dependencies'."\n".'None',
    '## Dependencies'."\n".'- #98', $fixture['body'])]);
$assert($admit($dependent)['admitted'] === false, 'Open dependency blocks.');
$assert($admit($dependent, static fn (int $number): bool => $number === 98)['admitted'],
    'Closed dependency unblocks.');
$assert($admit(array_replace($fixture, ['is_pull_request' => true]))['admitted'] === false,
    'PRs cannot be intake candidates.');
echo "Engineering Autopilot admission policy passed.\n";
