<?php
declare(strict_types=1);

use App\Engineering\Application\Acceptance\EngineeringV01AcceptanceService;
use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Service\EngineeringFinalizeService;
use App\Engineering\Application\Service\EngineeringHumanDecisionService;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Infrastructure\Acceptance\DeterministicEngineeringRepositoryGateway;
use App\Engineering\Infrastructure\Acceptance\EngineeringV01CrashSupport;
use App\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('APP_ENV=test');
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';

$organizationId = trim((string) getenv('COS_ORGANIZATION_ID'));
if ($organizationId === '') {
    throw new RuntimeException('COS_ORGANIZATION_ID is required for Engineering V0.1 operational acceptance.');
}

$boot = static function (): array {
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    return [
        $kernel,
        $container->get(EngineeringOrchestrator::class),
        $container->get(EngineeringStatusService::class),
        $container->get(EngineeringV01AcceptanceService::class),
        $container->get(EngineeringHumanDecisionService::class),
        $container->get(EngineeringFinalizeService::class),
        $container->get(DeterministicEngineeringRepositoryGateway::class),
        $container->get(EngineeringV01CrashSupport::class),
    ];
};

$makeFeature = static function (EngineeringOrchestrator $engineering, string $scenario, string $organizationId): string {
    $request = new EngineeringRequest(
        requestId: EngineeringId::generate(),
        description: '[scenario:'.$scenario.'] Execute the complete COS Engineering Agents V0.1 acceptance lifecycle.',
        title: 'Engineering V0.1 '.$scenario.' acceptance',
        sourceType: 'acceptance',
        sourceReference: 'tests/integration/engineering_v01_operational.php',
        priority: 'P1',
    );
    return $engineering->create($request, $organizationId, 'v01-acceptance');
};

$assertAcceptance = static function (EngineeringV01AcceptanceService $acceptance, string $featureId, string $scenario): void {
    $result = $acceptance->verify($featureId, $scenario);
    if (!$result['passed']) {
        $details = [];
        foreach ($result['checks'] as $check) {
            if (!is_array($check) || ($check['passed'] ?? false) === true) continue;
            $details[] = (string) ($check['id'] ?? 'unknown').': '.(string) ($check['detail'] ?? 'no detail');
        }
        throw new RuntimeException(
            'Engineering V0.1 '.$scenario.' acceptance failed for '.$featureId.': '.implode(' | ', $details),
        );
    }
};

$runProcess = static function (string $command, int $expectedExit): string {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) throw new RuntimeException('Could not start acceptance subprocess.');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== $expectedExit) {
        throw new RuntimeException(
            "Acceptance subprocess exit mismatch. Expected {$expectedExit}, got {$exit}.\nCommand: {$command}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
        );
    }
    return (string) $stdout;
};

[
    $kernel,
    $engineering,
    $status,
    $acceptance,
    $decisions,
    $finalize,
    $repository,
    $crash,
] = $boot();

$repository->reset();

// 1. Successful real persisted lifecycle + verified human merge -> DONE.
$successFeature = $makeFeature($engineering, 'success', $organizationId);
$successStart = $engineering->start(
    $successFeature,
    $organizationId,
    'engineering:v01:success:'.EngineeringId::generate(),
);
if ($successStart->state !== 'READY_FOR_HUMAN_APPROVAL') {
    throw new RuntimeException('Success scenario did not reach READY_FOR_HUMAN_APPROVAL: '.$successStart->state);
}
$assertAcceptance($acceptance, $successFeature, 'success');
$successStatus = $status->status($successFeature);
$successPr = (int) ($successStatus['artifacts']['DEVELOPMENT_RESULT']['content']['pull_request'] ?? 0);
if ($successPr <= 0) throw new RuntimeException('Success scenario did not persist a pull request.');
$repository->markMerged($successPr, 'fixture-human');
$done = $finalize->finalize($successFeature, 'fixture-human');
if (($done['state'] ?? null) !== 'DONE') throw new RuntimeException('Human merge confirmation did not transition workflow to DONE.');
$doneStatus = $status->status($successFeature);
$doneReport = $doneStatus['artifacts']['FINAL_REPORT']['content'] ?? [];
if (
    ($doneReport['recommendation'] ?? null) !== 'DONE'
    || trim((string) ($doneReport['approved_by'] ?? '')) === ''
    || trim((string) ($doneReport['merge_revision'] ?? '')) === ''
) {
    throw new RuntimeException('DONE Final Report is missing human merge evidence.');
}
$assertAcceptance($acceptance, $successFeature, 'success');

// 2. Injected review defect -> Developer fix -> Reviewer rerun -> QA -> READY.
$fixFeature = $makeFeature($engineering, 'fix-loop', $organizationId);
$fixStart = $engineering->start(
    $fixFeature,
    $organizationId,
    'engineering:v01:fix-loop:'.EngineeringId::generate(),
);
if ($fixStart->state !== 'READY_FOR_HUMAN_APPROVAL') {
    throw new RuntimeException('Fix-loop scenario did not reach READY_FOR_HUMAN_APPROVAL: '.$fixStart->state);
}
$assertAcceptance($acceptance, $fixFeature, 'fix-loop');

// 3. Real HUMAN_DECISION_REQUIRED stop -> answer -> exact persisted state resume.
$humanFeature = $makeFeature($engineering, 'human-gate', $organizationId);
$humanStart = $engineering->start(
    $humanFeature,
    $organizationId,
    'engineering:v01:human-gate:'.EngineeringId::generate(),
);
if ($humanStart->state !== 'HUMAN_DECISION_REQUIRED') {
    throw new RuntimeException('Human-gate scenario did not stop at HUMAN_DECISION_REQUIRED: '.$humanStart->state);
}
$humanStatus = $status->status($humanFeature);
$open = $humanStatus['open_human_decisions'] ?? [];
if (count($open) !== 1) throw new RuntimeException('Human-gate scenario must create exactly one blocking decision.');
$requestId = (string) ($open[0]['id'] ?? '');
if ($requestId === '') throw new RuntimeException('Human-gate decision request id is missing.');
$humanResult = $decisions->answerAndResume(
    requestId: $requestId,
    selectedOption: 'CONTINUE',
    comment: 'V0.1 persisted acceptance decision.',
    decidedBy: 'fixture-human',
    organizationId: $organizationId,
    correlationId: 'engineering:v01:human-resume:'.EngineeringId::generate(),
);
if ($humanResult->state !== 'READY_FOR_HUMAN_APPROVAL') {
    throw new RuntimeException('Human-gate scenario did not resume to READY_FOR_HUMAN_APPROVAL: '.$humanResult->state);
}
$assertAcceptance($acceptance, $humanFeature, 'human-gate');

// Recovery runs use actual process termination so the stage catch block cannot clean up RUNNING AgentRun.
// Each required interruption state is exercised by a separate persisted feature.
$recoveryCases = [
    'QA_PLANNER' => 'QA_PLANNING',
    'PRINCIPAL_ARCHITECT' => 'ARCHITECTURE_PENDING',
    'DEVELOPER' => 'DEVELOPMENT_RUNNING',
    'REVIEWER' => 'REVIEW_PENDING',
    'QA_EXECUTOR' => 'QA_PENDING',
];

foreach ($recoveryCases as $crashRole => $expectedState) {
    $featureId = $makeFeature($engineering, 'recovery', $organizationId);
    $featureArg = escapeshellarg($featureId);
    $roleArg = escapeshellarg($crashRole);

    $runProcess(
        'APP_ENV=test APP_DEBUG=1 COS_ENGINEERING_FIXTURE_CRASH_ROLE='.$roleArg.' '
        .escapeshellarg(PHP_BINARY).' bin/console cos:engineering:start '.$featureArg,
        91,
    );

    $running = $crash->runningAgentRun($featureId);
    if (!is_array($running) || ($running['agent_role'] ?? null) !== $crashRole) {
        throw new RuntimeException('Crash scenario did not leave the expected RUNNING '.$crashRole.' AgentRun.');
    }
    if ($crash->workflowState($featureId) !== $expectedState) {
        throw new RuntimeException('Crash scenario state mismatch for '.$crashRole.'.');
    }
    if ($crash->backdateRunningAgentRun($featureId) !== 1) {
        throw new RuntimeException('Crash scenario could not backdate exactly one stale AgentRun for '.$crashRole.'.');
    }

    $runProcess(
        'APP_ENV=test APP_DEBUG=1 COS_ENGINEERING_FIXTURE_CRASH_ROLE= '
        .escapeshellarg(PHP_BINARY).' bin/console cos:engineering:continue '.$featureArg,
        0,
    );

    // Subprocess mutations bypass this kernel's ORM identity map. Reboot before reading persisted truth.
    $kernel->shutdown();
    [
        $kernel,
        $engineering,
        $status,
        $acceptance,
        $decisions,
        $finalize,
        $repository,
        $crash,
    ] = $boot();

    $recoveredStatus = $status->status($featureId);
    if (($recoveredStatus['workflow']['state'] ?? null) !== 'READY_FOR_HUMAN_APPROVAL') {
        throw new RuntimeException('Recovered '.$crashRole.' scenario did not reach READY_FOR_HUMAN_APPROVAL.');
    }
    $assertAcceptance($acceptance, $featureId, 'recovery');
}

$kernel->shutdown();

echo "Engineering V0.1 operational acceptance passed: success + human merge/DONE + fix-loop + human gate + 5-stage crash recovery.\n";
