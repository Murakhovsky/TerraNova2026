<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
use Kernel\Action\Action;

/** Live Sales approval scope and canonical Action receipt inside the native transaction. */
interface SalesOrderActionAuthority
{
    public function assertApprovedOrderExecution(Action $action):void;
}
