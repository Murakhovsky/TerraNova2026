<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;
use InvalidArgumentException;

final readonly class AiOperationDefinition
{
    public function __construct(public AiOperation $operation, public string $operationId, public string $promptVersion, public string $model, public string $schemaVersion, public int $timeoutSeconds=30, public int $maxRetries=2, public int $tokenBudget=2000)
    {
        if ($operationId==='' || $promptVersion==='' || $model==='' || $schemaVersion==='' || $timeoutSeconds<1 || $maxRetries<0 || $tokenBudget<1) throw new InvalidArgumentException('Invalid AI operation definition.');
    }
}
