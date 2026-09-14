<?php
declare(strict_types=1);

namespace Kernel\Rule\Contract;

use Kernel\Event\DomainEvent;
use Kernel\Rule\ProcessEvaluation;

interface RuleEvaluationRepositoryInterface
{
    public function save(DomainEvent $event, ProcessEvaluation $evaluation, array $context): void;
}
