<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Contract\LlmProviderInterface;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentRun;
use Kernel\Agent\Model\AgentStep;
use RuntimeException;
use Throwable;

final readonly class AgentRuntimeEngine implements AgentRuntimeInterface
{
    public function __construct(private LlmProviderInterface $llm)
    {
    }

    public function execute(AgentInstance $instance, AgentContext $context): AgentRun
    {
        if (!$instance->enabled || !$instance->agent->definition->enabled) {
            throw new RuntimeException('Agent instance is disabled.');
        }

        $run = new AgentRun($this->id(), $instance, $context);
        $run->queue();
        $run->start();

        $step = new AgentStep($this->id(), 1, 'llm', [
            'agent' => $instance->agent->id,
            'definition_version' => $instance->agent->definition->version,
        ]);
        $run->addStep($step);
        $step->queue();
        $step->start();

        try {
            $output = $this->llm->execute($instance->agent->definition, $context);
            $step->complete([
                'provider' => $output->provider,
                'model' => $output->model,
                'structured' => $output->structured,
            ]);
            $run->complete($output);
        } catch (Throwable $error) {
            $step->fail($error->getMessage());
            $run->fail($error->getMessage());
        }

        return $run;
    }

    private function id(): string
    {
        return bin2hex(random_bytes(16));
    }
}
