<?php
declare(strict_types=1);

namespace App\Engineering\Application\Manager;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Context\RepositoryContextMap;
use App\Engineering\Domain\Agent\EngineeringAgentTask;

final readonly class ManagerAnalysisResult
{
    public function __construct(
        public RepositoryContextMap $contextMap,
        public EngineeringAgentTask $task,
        public EngineeringAgentRunResult $run,
        public array $featureSpecification,
    ) {
    }
}
