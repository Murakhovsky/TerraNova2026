<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model\Step;

use InvalidArgumentException;
use Kernel\Workflow\Model\StepType;

final readonly class SystemStep extends Step
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        string $id,
        string $name,
        public string $operation,
        public array $payload = [],
    ) {
        parent::__construct($id, $name);
        if (trim($operation) === '') {
            throw new InvalidArgumentException('SystemStep requires an operation.');
        }
    }

    public function type(): StepType { return StepType::SYSTEM; }
}
