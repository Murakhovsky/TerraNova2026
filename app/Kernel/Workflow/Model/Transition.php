<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Transition extends ValueObject
{
    public function __construct(
        public string $from,
        public string $to,
        public ?Condition $condition = null,
        public ?string $label = null,
    ) {
        if (trim($from) === '' || trim($to) === '') {
            throw new InvalidArgumentException('Workflow transition requires from and to step ids.');
        }
    }
}
