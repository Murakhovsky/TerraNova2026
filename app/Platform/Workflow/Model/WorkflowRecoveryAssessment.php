<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;

/** Read-only diagnostic result until an explicitly authorized correction. */
final readonly class WorkflowRecoveryAssessment
{
    public function __construct(
        public WorkflowRunIdentity $identity,
        public WorkflowRecoveryDisposition $disposition,
        public string $reason,
        public bool $externalOutcomeUnknown = false,
    ) {
        if (trim($reason) === ''
            || ($externalOutcomeUnknown && $disposition === WorkflowRecoveryDisposition::SAFE_PRE_EFFECT_RETRY)) {
            throw new InvalidArgumentException('Uncertain external Workflow effect cannot be retried automatically.');
        }
    }
}
