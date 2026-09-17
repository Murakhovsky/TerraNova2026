<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

enum DeliveryStatus: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';
}
