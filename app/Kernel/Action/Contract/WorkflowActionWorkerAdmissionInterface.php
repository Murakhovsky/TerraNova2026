<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\Action;

/** Last-moment, read-only admission before invoking a Domain Action handler. */
interface WorkflowActionWorkerAdmissionInterface
{
    public function assertAuthorized(Action $action): void;
}
