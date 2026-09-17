<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use InvalidArgumentException;
use Kernel\Workflow\Model\StepType;

final readonly class WaitStep extends Step
{
    public function __construct(
        string $id,
        string $name,
        public string $signal,
    ) {
        parent::__construct($id, $name);
        if (trim($signal) === '') {
            throw new InvalidArgumentException('WaitStep requires a signal name.');
        }
    }

    public function type(): StepType { return StepType::WAIT; }
}
