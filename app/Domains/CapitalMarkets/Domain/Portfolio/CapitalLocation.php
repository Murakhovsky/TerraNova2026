<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
final readonly class CapitalLocation {
 public const TYPES=['BANK','BROKER','CEX','DEX_WALLET','CUSTODIAN','ONCHAIN_WALLET'];
 public function __construct(public string $id,public string $type,public string $currency,public Decimal $total,public Decimal $available,public ?string $venueId=null){
  if($id===''||!in_array($type,self::TYPES,true)||$currency==='')throw new InvalidArgumentException('Invalid capital location.');
  if($total->isNegative()||$available->isNegative())throw new InvalidArgumentException('Capital cannot be negative.');
 }
}
