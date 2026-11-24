<?php
declare(strict_types=1);
namespace Platform\Notification\Contract;
/** Read authoritative transport handoff state, not mailbox/customer delivery. */
interface NotificationHandoffReadPort
{
    public function status(string $organizationId,string $notificationId):?array;
}
