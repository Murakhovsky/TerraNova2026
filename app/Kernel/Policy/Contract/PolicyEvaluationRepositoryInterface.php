<?php
declare(strict_types=1);

namespace Kernel\Policy\Contract;

use Kernel\Action\Action;
use Kernel\Policy\PolicyEvaluation;

interface PolicyEvaluationRepositoryInterface
{
    public function save(Action $action, PolicyEvaluation $evaluation, array $context): void;
}
