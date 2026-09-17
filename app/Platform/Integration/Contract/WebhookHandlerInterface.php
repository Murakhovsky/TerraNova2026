<?php
declare(strict_types=1);

namespace Platform\Integration\Contract;

use Platform\Integration\Model\Webhook;
use Platform\Integration\Model\WebhookResult;

interface WebhookHandlerInterface
{
    public function supports(Webhook $webhook): bool;

    public function handle(Webhook $webhook): WebhookResult;
}
