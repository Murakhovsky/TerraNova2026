<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum CommunicationDirection: string
{
    use HasStringValues;

    case Inbound = 'INBOUND';
    case Outbound = 'OUTBOUND';
}
