<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;

use DomainException;

/** Native COS stock has one owner: Product Management. External order intake
 * grants no authority over physical inventory, provider fulfillment or returns. */
final class SalesOrderInventoryOwnership
{
    public static function authority(string $channel):string
    {
        return $channel==='native'?'local':'external_review';
    }

    public static function assertOperation(string $channel,string $operation):void
    {
        if(self::authority($channel)!=='local'&&$operation!=='confirm')
            throw new DomainException('External order inventory and fulfillment require verified ownership reconciliation.');
    }
}
