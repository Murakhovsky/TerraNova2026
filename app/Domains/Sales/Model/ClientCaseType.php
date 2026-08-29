<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum ClientCaseType: string
{
    use HasStringValues;

    case Buy = 'buy';
    case Sell = 'sell';
    case Rent = 'rent';
    case LeaseOut = 'lease_out';
    case Repair = 'repair';
    case Investment = 'investment';
    case Management = 'management';
    case Inheritance = 'inheritance';
    case Other = 'other';
}
