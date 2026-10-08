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

$runtimeFailure = new class implements AgentRuntimeInterface {
    public int $calls = 0;

    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        ++$this->calls;
        $run = new AgentRun('transport-failure-'.$this->calls, $instance, $context);
        $run->queue();
        $run->start();
        $run->fail('OpenAI transport error: Operation timed out after 90000 milliseconds');
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

try {
    (new EngineeringAgentRunner($runtimeFailure))->run($task, 'platform', 'transport-failure-trace');
    throw new RuntimeException('Runtime/provider failure did not fail the Engineering run.');
} catch (EngineeringAgentTechnicalFailureException $error) {
    if ($error->technicalRetries !== 0) throw new RuntimeException('Provider failure must not create Engineering-level technical retries.');
    if ($runtimeFailure->calls !== 1) throw new RuntimeException('Provider/runtime failure must execute exactly once; provider adapter owns transport retries.');
}

$invalidThenCorrected = new class implements AgentRuntimeInterface {
    public int $calls = 0;

    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        ++$this->calls;
        $run = new AgentRun('validation-retry-'.$this->calls, $instance, $context);
        $run->queue();
        $run->start();

        if ($this->calls === 1) {
            $run->complete(new AgentOutput(
                structured: ['status' => 'APPROVED'],
                provider: 'fixture',
                model: 'fixture-reviewer',
            ));
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

$result = (new EngineeringAgentRunner($invalidThenCorrected))->run($task, 'platform', 'validation-retry-trace');
if ($invalidThenCorrected->calls !== 2) throw new RuntimeException('Invalid structured output must receive one correction retry.');
if ($result->technicalRetries !== 1) throw new RuntimeException('Validation correction retry count was not preserved.');

echo "Engineering technical retry policy passed.\n";
