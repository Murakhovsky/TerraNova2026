<?php
declare(strict_types=1);

namespace Kernel\Execution;

interface ClassifiedExecutionFailureInterface
{
    public function failureKind(): ExecutionFailureKind;
}
