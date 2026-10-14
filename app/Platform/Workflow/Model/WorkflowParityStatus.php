<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

/**
 * MATCH means equivalent current read projection and trustworthy supplied
 * receipts; PARTIAL means that business evidence or migration metadata is
 * not complete; DRIFT forbids a cutover.
 */
enum WorkflowParityStatus: string
{
    case MATCH = 'match';
    case PARTIAL = 'partial';
    case DRIFT = 'drift';
    case NO_TARGET = 'no_target';
}
