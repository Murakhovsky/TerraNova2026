<?php
declare(strict_types=1);

namespace Platform\Notification\Application\Command;

use Kernel\Application\Command\CommandInterface;
use Platform\Notification\Model\Notification;

final readonly class SendNotificationCommand implements CommandInterface
{
    public function __construct(public Notification $notification)
    {
    }
}
