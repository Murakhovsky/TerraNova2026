<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringAgentRunner;
use App\Engineering\Application\Agent\EngineeringAgentTechnicalFailureException;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentOutput;
use Kernel\Agent\Model\AgentRun;

$runtime = new class implements AgentRuntimeInterface {
    public int $calls = 0;

    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        ++$this->calls;
        $run = new AgentRun('retry-run-'.$this->calls, $instance, $context);
        $run->queue();
        $run->start();

        if ($this->calls === 1) {
            $run->fail('temporary provider failure');
            return $run;
        }

        $run->complete(new AgentOutput(
            structured: [
                'status' => 'APPROVED',
                'reviewed_revision' => 'abc123',
                'base_revision' => 'base123',
                'pull_request' => 42,
                'preflight' => [
                    'status' => 'PASS',
                    'reviewed_revision' => 'abc123',
                    'diff_complete' => true,
                    'required_artifacts_present' => true,
                    'ci_evidence_available' => true,
                    'blockers' => [],
                ],
                'summary' => 'Independent review passed.',
                'issues' => [],
                'correctness' => ['status' => 'PASS', 'findings' => []],
                'architecture' => ['compliant' => true, 'findings' => []],
                'security' => ['status' => 'PASS', 'findings' => []],
                'maintainability' => ['status' => 'PASS', 'findings' => []],
                'database' => ['status' => 'NOT_APPLICABLE', 'findings' => []],
                'api' => ['status' => 'NOT_APPLICABLE', 'findings' => []],
                'tests' => ['status' => 'PASS', 'findings' => []],
                'acceptance_criteria' => [['id' => 'AC-001', 'result' => 'PASS', 'evidence' => 'fixture']],
                'ci' => ['state' => 'PASSED', 'total' => 1, 'passed' => 1, 'failed' => 0, 'pending' => 0, 'checks' => []],
                'unresolved_blockers' => [],
                'unresolved_majors' => [],
                'recommendation' => 'continue',
            ],
            provider: 'fixture',
            model: 'fixture-reviewer',
        ));
        return $run;
    }
};

$task = new EngineeringAgentTask(
    EngineeringId::generate(),
    EngineeringId::generate(),
    AgentRole::REVIEWER,
    'Review revision.',
    [],
    [],
    [],
    'reviewer-result-v0.1',
    ['valid output'],
    'feature:reviewer:1',
    ['logical_attempt' => 4],
);

$result = (new EngineeringAgentRunner($runtime))->run($task, 'platform', 'retry-trace');
if ($runtime->calls !== 2) throw new RuntimeException('Technical failure did not retry exactly once.');
if ($result->technicalRetries !== 1) throw new RuntimeException('Technical retry count was not preserved.');

$alwaysFail = new class implements AgentRuntimeInterface {
    public int $calls = 0;
    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        ++$this->calls;
        $run = new AgentRun('failed-run-'.$this->calls, $instance, $context);
        $run->queue();
        $run->start();
        $run->fail('timeout');
        return $run;
    }
};

try {
    (new EngineeringAgentRunner($alwaysFail))->run($task, 'platform', 'retry-fail-trace');
    throw new RuntimeException('Exhausted technical retries did not fail.');
} catch (EngineeringAgentTechnicalFailureException $error) {
    if ($error->technicalRetries !== 2) throw new RuntimeException('Technical retry limit must be 2.');
    if ($alwaysFail->calls !== 3) throw new RuntimeException('Technical retries must be initial attempt plus 2 retries.');
}

echo "Engineering technical retry policy passed.\n";
