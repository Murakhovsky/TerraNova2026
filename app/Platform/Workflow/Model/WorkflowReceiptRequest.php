<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;

/** A receipt is checked using the authorized actor and pinned source identity. */
final readonly class WorkflowReceiptRequest
{
    public function __construct(
        public WorkflowRunIdentity $identity,
        public TenantContext $actor,
        public string $stepId,
        public string $resultReference,
        public string $inputDigest,
    ) {
        if ($actor->organizationId()->value() !== $identity->organizationId
            || trim($stepId) === '' || trim($resultReference) === ''
            || !preg_match('/^[a-f0-9]{64}$/', $inputDigest)) {
            throw new InvalidArgumentException('Workflow receipt scope/provenance is invalid.');
        }
    }
}
