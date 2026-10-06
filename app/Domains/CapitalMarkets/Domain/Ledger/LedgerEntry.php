<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Ledger;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class LedgerEntry extends ValueObject
{
    public function __construct(
        public string $account,
        public Decimal $debit,
        public Decimal $credit,
        public string $assetKey='UNIT',
    ){
        if($account===''||$assetKey===''){
            throw new InvalidArgumentException('Ledger entry requires account and asset identity.');
        }
        if(($debit->isPositive()&&$credit->isPositive())||$debit->isNegative()||$credit->isNegative()){
            throw new InvalidArgumentException('Ledger entry must target exactly one non-negative side of one account.');
        }
    }
}
