<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;
use Kernel\Workflow\Model\WorkflowStatus;

/** Read-only persisted execution state. Never proof of a business Goal outcome. */
final readonly class WorkflowRunSnapshot
{
    public function __construct(
        public WorkflowRunIdentity $identity,
        public string $definitionId,
        public string $definitionVersion,
        public WorkflowStatus $status,
        public int $revision,
        public string $correlationId,
        public ?string $sourcePlanId = null,
        public ?string $checkpointReference = null,
        public ?string $sourcePlanDigest = null,
    ) {
        if ($revision < 1 || trim($definitionId) === '' || trim($definitionVersion) === ''
            || trim($correlationId) === '') {
            throw new InvalidArgumentException('Invalid versioned Workflow Run snapshot.');
        }
        if (($sourcePlanId === null) !== ($sourcePlanDigest === null)
            || ($sourcePlanDigest !== null && !preg_match('/^[a-f0-9]{64}$/', $sourcePlanDigest))) {
            throw new InvalidArgumentException('Workflow Run must preserve approved Plan digest.');
        }
    }
}
