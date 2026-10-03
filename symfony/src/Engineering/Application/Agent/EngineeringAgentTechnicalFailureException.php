<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use RuntimeException;
use Throwable;

final class EngineeringAgentTechnicalFailureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $technicalRetries,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
