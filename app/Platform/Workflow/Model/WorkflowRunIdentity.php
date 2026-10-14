<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

/** Logical identity is ALWAYS scoped by the organization. */
final readonly class WorkflowRunIdentity
{
    public function __construct(public string $organizationId, public string $runId)
    {
        if (trim($organizationId) === '' || trim($runId) === ''
            || strlen($organizationId) > 190 || strlen($runId) > 190) {
            throw new InvalidArgumentException('Workflow Run requires scoped organization and run identifiers.');
        }
    }
}
