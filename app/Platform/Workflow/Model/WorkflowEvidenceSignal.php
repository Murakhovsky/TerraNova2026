<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

/**
 * Operator requests re-attestation of a pinned read-only Domain fact.
 * This DTO never grants permission and never carries caller-supplied evidence data.
 */
final readonly class WorkflowEvidenceSignal
{
    public function __construct(
        public WorkflowRunCommand $command,
        public string $stepId,
        public int $expectedStepRevision,
        public string $resultReference,
    ) {
        if (trim($stepId)==='' || $expectedStepRevision<1
            || trim($resultReference)==='' || strlen($resultReference)>255) {
            throw new InvalidArgumentException('Wait/Signal needs bounded pinned proof and Step CAS.');
        }
    }
}
