<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class RunSalesAgentCommand implements CommandInterface
{
    /** @param array<string,mixed> $contextReferences */
    public function __construct(
        public string $organizationId,
        public string $agentName,
        public string $subjectType,
        public string $subjectId,
        public string $question,
        public string $correlationId,
        public string $idempotencyKey,
        public array $contextReferences = [],
    ) {
    }
}
