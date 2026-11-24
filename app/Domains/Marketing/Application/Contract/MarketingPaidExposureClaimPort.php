<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Action\Action;

/** Cross-domain Finance write boundary. Finance independently rechecks the approved worker. */
interface MarketingPaidExposureClaimPort
{
    public function assertActive(Action $action):void;
    public function claim(Action $action,array $funding):array;
}
