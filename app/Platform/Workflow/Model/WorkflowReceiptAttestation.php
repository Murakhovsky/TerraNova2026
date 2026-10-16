<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

/** Attestation is not equivalent to Goal Outcome success. */
final readonly class WorkflowReceiptAttestation
{
    public function __construct(
        public WorkflowRunIdentity $identity,
        public string $stepId,
        public bool $verified,
        public string $provider,
        public ?string $evidenceReference,
        public string $correlationId,
    ) {
        if (trim($stepId) === '' || trim($provider) === '' || trim($correlationId) === ''
            || ($verified && ($evidenceReference === null || trim($evidenceReference) === ''))) {
            throw new InvalidArgumentException('Verified Workflow receipt requires authoritative evidence.');
        }
    }
}
