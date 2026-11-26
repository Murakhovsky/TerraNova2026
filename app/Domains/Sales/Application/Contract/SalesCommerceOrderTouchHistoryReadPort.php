<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
/** Independently observed complete bounded provider visit history. Never a financial or causal fact. */
interface SalesCommerceOrderTouchHistoryReadPort
{
    public function touchHistory(string $org,string $order):?array;
}
