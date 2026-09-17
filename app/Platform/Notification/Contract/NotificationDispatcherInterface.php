<?php
declare(strict_types=1);

namespace Platform\Notification\Contract;

use Platform\Notification\Model\Delivery;
use Platform\Notification\Model\Notification;

interface NotificationDispatcherInterface
{
    public function send(Notification $notification): Delivery;
}
