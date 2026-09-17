<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Pipeline;

use InvalidArgumentException;

final readonly class PipelineStage
{
    public function __construct(
        public PipelineStageId $id,
        public string $code,
        public string $name,
        public int $order,
        public bool $isTerminal,
        public bool $isWon,
        public bool $isLost,
        public float $probabilityDefault,
    ) {
        if (trim($code) === '' || trim($name) === '') {
            throw new InvalidArgumentException('Pipeline stage code and name are required.');
        }
        if ($probabilityDefault < 0 || $probabilityDefault > 100) {
            throw new InvalidArgumentException('Pipeline stage probability must be between 0 and 100.');
        }
        if (($isWon || $isLost) && !$isTerminal) {
            throw new InvalidArgumentException('Won and lost stages must be terminal.');
        }
        if ($isWon && $isLost) {
            throw new InvalidArgumentException('A stage cannot be both won and lost.');
        }
    }
}
