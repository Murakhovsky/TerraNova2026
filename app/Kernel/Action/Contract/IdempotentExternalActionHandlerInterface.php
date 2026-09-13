<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\Action;

interface IdempotentExternalActionHandlerInterface extends ActionHandlerInterface
{
    /**
     * Return the stable key that must be propagated to the external side effect.
     * The same action retried after an ambiguous failure must resolve to the same key.
     */
    public function idempotencyKey(Action $action): string;
}
