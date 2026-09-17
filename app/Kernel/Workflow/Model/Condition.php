<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Condition extends ValueObject
{
    public function __construct(
        public string $path,
        public ConditionOperator $operator,
        public mixed $value = null,
    ) {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Workflow condition path cannot be empty.');
        }
    }
}
