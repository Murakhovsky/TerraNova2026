<?php
declare(strict_types=1);

namespace Platform\Notification\Application\Command;

use Kernel\Application\Command\CommandHandlerInterface;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Model\Delivery;

final readonly class SendNotificationHandler implements CommandHandlerInterface
{
    public function __construct(private NotificationDispatcherInterface $notifications)
    {
    }

    public function __invoke(SendNotificationCommand $command): Delivery
    {
        return $this->notifications->send($command->notification);
    }
}
