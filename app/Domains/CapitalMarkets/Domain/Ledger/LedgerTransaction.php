<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Ledger;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class LedgerTransaction extends ValueObject
{
    /** @param list<LedgerEntry> $entries */
    public function __construct(
        public string $id,
        public string $idempotencyKey,
        public DateTimeImmutable $postedAt,
        public array $entries,
    ){
        if($id===''||$idempotencyKey===''||count($entries)<2){
            throw new InvalidArgumentException('Ledger transaction requires identity and at least two entries.');
        }

        /** @var array<string,array{debit:Decimal,credit:Decimal}> $totals */
        $totals=[];
        foreach($entries as $entry){
            if(!$entry instanceof LedgerEntry)throw new InvalidArgumentException('Ledger entries must be typed.');
            if(!isset($totals[$entry->assetKey])){
                $totals[$entry->assetKey]=['debit'=>Decimal::fromString('0'),'credit'=>Decimal::fromString('0')];
            }
            $totals[$entry->assetKey]['debit']=DecimalMath::add($totals[$entry->assetKey]['debit'],$entry->debit);
            $totals[$entry->assetKey]['credit']=DecimalMath::add($totals[$entry->assetKey]['credit'],$entry->credit);
        }

        foreach($totals as $assetKey=>$total){
            if($total['debit']->compareTo($total['credit'])!==0){
                throw new DomainException('LEDGER_IMBALANCE: '.$assetKey);
            }
        }
    }
}
