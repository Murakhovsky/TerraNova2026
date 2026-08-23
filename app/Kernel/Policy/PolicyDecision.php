<?php
declare(strict_types=1);

namespace Kernel\Policy;

enum PolicyDecision: string
{
    case Auto = 'AUTO';
    case ApprovalRequired = 'APPROVAL_REQUIRED';
    case Denied = 'DENIED';
}
