<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum SalesPriority: string
{
    use HasStringValues;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
