<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;

/** Current authenticated actor and optimistic execution fence. */
final readonly class WorkflowRunCommand
{
    public function __construct(
        public WorkflowRunIdentity $identity,
        public TenantContext $actor,
        public int $expectedRevision,
        public string $correlationId,
        public ?string $signalReference = null,
    ) {
        if ($expectedRevision < 1 || trim($correlationId) === '') {
            throw new InvalidArgumentException('Workflow command requires revision and correlation.');
        }
        if ($actor->organizationId()->value() !== $identity->organizationId) {
            throw new InvalidArgumentException('Workflow command cannot operate across tenants.');
        }
    }
}
