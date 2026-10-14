<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

/** SAFE_PRE_EFFECT_RETRY must never be used for an uncertain external effect. */
enum WorkflowRecoveryDisposition: string
{
    case NO_ACTION = 'no_action';
    case RECONCILE_ONLY = 'reconcile_only';
    case SAFE_PRE_EFFECT_RETRY = 'safe_pre_effect_retry';
    case MANUAL_REQUIRED = 'manual_required';
}
