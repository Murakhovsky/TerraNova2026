<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

use DomainException;

final readonly class PipelineStageDefinition
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public int $order,
        public bool $isTerminal,
        public bool $isWon,
        public bool $isLost,
        public float $probabilityDefault,
    ) {
        if ($id === '' || $code === '' || $name === '') throw new DomainException('Stage identity, code and name are required.');
        if ($probabilityDefault < 0 || $probabilityDefault > 100) throw new DomainException('Stage probability must be between 0 and 100.');
        if (($isWon || $isLost) && !$isTerminal) throw new DomainException('Won and lost stages must be terminal.');
        if ($isWon && $isLost) throw new DomainException('A stage cannot be both won and lost.');
    }
}
