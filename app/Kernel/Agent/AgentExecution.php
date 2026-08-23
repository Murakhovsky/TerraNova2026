<?php
declare(strict_types=1);

namespace Kernel\Agent;

use Kernel\Action\ActionProposal;

final readonly class AgentExecution
{
    public function __construct(
        public string $runId,
        public AgentResult $result,
        /** @var list<ActionProposal> */
        public array $proposals,
    ) {}
}
