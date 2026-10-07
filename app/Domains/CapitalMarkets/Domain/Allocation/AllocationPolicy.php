<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Allocation;
use InvalidArgumentException;
final readonly class AllocationPolicy {
 public const TYPES=['RULE_BASED','SCORE_BASED'];
 public function __construct(public string $id,public string $version,public string $type,public string $mode,public array $weights,public array $constraints,public float $rebalanceThreshold=0.05,public int $rebalanceCooldownSeconds=300){
  if($id===''||$version===''||!in_array($type,self::TYPES,true))throw new InvalidArgumentException('Invalid allocation policy.');
  if(!in_array($mode,['CONSERVATIVE','BALANCED','AGGRESSIVE'],true))throw new InvalidArgumentException('Invalid portfolio mode.');
 }
}
