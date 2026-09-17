<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Assignment extends ValueObject
{
    public function __construct(
        public AssignmentType $type,
        public string $target,
    ) {
        if (trim($target) === '') {
            throw new InvalidArgumentException('Workflow assignment target cannot be empty.');
        }
    }
}
