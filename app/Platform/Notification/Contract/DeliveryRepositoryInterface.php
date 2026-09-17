<?php
declare(strict_types=1);

namespace Platform\Notification\Contract;

use Platform\Notification\Model\Delivery;

interface DeliveryRepositoryInterface
{
    public function save(Delivery $delivery): void;
}
