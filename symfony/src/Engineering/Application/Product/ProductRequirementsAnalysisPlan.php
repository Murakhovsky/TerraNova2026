<?php
declare(strict_types=1);

namespace App\Engineering\Application\Product;

use App\Engineering\Application\Context\RepositoryContextMap;
use App\Engineering\Domain\Agent\EngineeringAgentTask;

final readonly class ProductRequirementsAnalysisPlan
{
    public function __construct(
        public RepositoryContextMap $contextMap,
        public EngineeringAgentTask $task,
    ) {}
}
