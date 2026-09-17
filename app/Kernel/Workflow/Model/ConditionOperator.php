<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

enum ConditionOperator: string
{
    case EQ = 'eq';
    case NE = 'ne';
    case GT = 'gt';
    case GTE = 'gte';
    case LT = 'lt';
    case LTE = 'lte';
    case EXISTS = 'exists';
    case TRUTHY = 'truthy';
}
