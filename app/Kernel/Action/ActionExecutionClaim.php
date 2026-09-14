<?php
declare(strict_types=1);

namespace Kernel\Action;

use InvalidArgumentException;

final readonly class ActionExecutionClaim
{
    public function __construct(
        public Action $action,
        public int $attempt,
        public string $workerId,
    ) {
        if ($this->attempt < 1) {
            throw new InvalidArgumentException('Action execution claim attempt must be positive.');
        }
        if (trim($this->workerId) === '') {
            throw new InvalidArgumentException('Action execution claim worker id cannot be empty.');
        }
    }
}
