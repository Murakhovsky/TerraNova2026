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
    public function __construct(public string $id,public string $idempotencyKey,public DateTimeImmutable $postedAt,public array $entries)
    {
        if($id===''||$idempotencyKey===''||count($entries)<2)throw new InvalidArgumentException('Ledger transaction requires identity and at least two entries.');
        $debits=Decimal::fromString('0');$credits=Decimal::fromString('0');
        foreach($entries as $entry){
            if(!$entry instanceof LedgerEntry)throw new InvalidArgumentException('Ledger entries must be typed.');
            $debits=DecimalMath::add($debits,$entry->debit);$credits=DecimalMath::add($credits,$entry->credit);
        }
        if($debits->compareTo($credits)!==0)throw new DomainException('LEDGER_IMBALANCE');
    }
}
