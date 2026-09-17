<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class WorkflowInstance
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public Workflow $workflow,
        public array $configuration = [],
        public bool $enabled = true,
    ) {
        if (trim($id) === '') throw new InvalidArgumentException('Workflow instance id cannot be empty.');
    }
}
