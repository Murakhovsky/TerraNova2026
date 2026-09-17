<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class AgentRunHistory
{
    /** @var list<TraceEvent> */
    private array $events = [];

    public function __construct(
        public readonly string $runId,
        public readonly OrganizationId $organizationId,
        public readonly string $agent,
        public readonly string $correlationId,
    ) {
        if (trim($this->runId) === '' || trim($this->agent) === '' || trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Agent run history requires run, agent and correlation id.');
        }
    }

    public function append(TraceEvent $event): void
    {
        $expected = count($this->events) + 1;
        if ($event->sequence !== $expected) {
            throw new InvalidArgumentException(sprintf('Trace sequence must be %d, got %d.', $expected, $event->sequence));
        }
        $this->events[] = $event;
    }

    /** @return list<TraceEvent> */
    public function events(): array { return $this->events; }
}
