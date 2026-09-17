<?php
declare(strict_types=1);

namespace Platform\Notification\Contract;

use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Delivery;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\RenderedNotification;

interface NotificationChannelInterface
{
    public function channel(): Channel;

    public function deliver(Notification $notification, RenderedNotification $rendered): Delivery;
}
