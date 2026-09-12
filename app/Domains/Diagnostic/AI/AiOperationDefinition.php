<?php
declare(strict_types=1);

namespace Domains\Diagnostic\AI;

use InvalidArgumentException;

final readonly class AiOperationDefinition
{
    public function __construct(
        public AiOperation $operation,
        public string $operationId,
        public string $promptVersion,
        public string $schemaVersion,
        public int $tokenBudget = 2000,
    ) {
        if ($operationId === '' || $promptVersion === '' || $schemaVersion === '' || $tokenBudget < 1) {
            throw new InvalidArgumentException('Invalid AI operation definition.');
        }
    }
}
