<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use InvalidArgumentException;
use Kernel\Workflow\Model\StepType;

abstract readonly class Step
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) {
            throw new InvalidArgumentException('Workflow step requires a canonical id.');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('Workflow step requires a name.');
        }
    }

    abstract public function type(): StepType;
}
