<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringAgentRunner;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentOutput;
use Kernel\Agent\Model\AgentRun;

$runtime = new class implements AgentRuntimeInterface {
    public ?AgentContext $context = null;
    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        $this->context = $context;
        $run = new AgentRun('kernel-run-1', $instance, $context);
        $run->queue();
        $run->start();
        $run->complete(new AgentOutput(
            structured: ['status' => 'SPECIFICATION_READY'],
            provider: 'fake',
            model: 'fake-engineering',
            usage: ['input_tokens' => 10, 'output_tokens' => 5],
        ));
        return $run;
    }
};

$task = new EngineeringAgentTask(
    EngineeringId::generate(),
    EngineeringId::generate(),
    AgentRole::ENGINEERING_MANAGER,
    'Formalize the request.',
    ['request' => 'Add filters'],
    ['repo:file'],
    ['no_repository_write'],
    'engineering-manager-result',
    ['scope explicit'],
    'feature:manager:1',
    ['repository_revision' => 'abc123'],
);

$result = (new EngineeringAgentRunner($runtime))->run($task, 'platform', 'trace-1');
if ($result->status !== 'completed') throw new RuntimeException('Engineering runner did not complete.');
if (($runtime->context?->metadata['idempotency_key'] ?? null) !== 'feature:manager:1') throw new RuntimeException('Idempotency key was not propagated.');
if (($runtime->context?->data['input_snapshot']['repository_revision'] ?? null) !== 'abc123') throw new RuntimeException('Input snapshot was not propagated.');

echo "Engineering agent runner passed.\n";
