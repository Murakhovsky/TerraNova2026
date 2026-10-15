<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

/**
 * No generic external retry is available. Unknown remote effects stay unknown.
 */
enum WorkflowRecoveryResolution: string
{
    case QUARANTINE = 'quarantine';
    case VERIFIED_COMPLETE = 'verified_complete';
    case RESET_READ_ONLY = 'reset_read_only';
    case CANCEL_UNCERTAIN = 'cancel_uncertain';
}
