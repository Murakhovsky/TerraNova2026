<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

/**
 * Local presentation contract, not a runtime response or permission grant.
 * An unresolved subject must never be used to read generic Agent history.
 */
final readonly class AgentRuntimeInspection
{
    /** @param array<string,mixed> $binding Server-owned presentation projection. */
    public function __construct(
        public bool $scopeResolved,
        public string $presentation,
        public array $binding,
    ) {}
}
