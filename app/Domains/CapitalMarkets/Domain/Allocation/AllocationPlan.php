<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Allocation;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
final readonly class AllocationPlan {
 /** @param list<AllocationItem> $allocations */
 public function __construct(public string $id,public string $portfolioId,public DateTimeImmutable $createdAt,public Decimal $capitalAvailable,public array $allocations,public array $reservations,public Decimal $expectedReturn,public int $expectedRisk,public int $expectedLiquidity,public Decimal $expectedDrawdown,public array $constraints,public string $reasoningSummary,public string $status,public string $policyVersion,public string $inputFingerprint){
  if($id===''||$portfolioId===''||$policyVersion===''||$inputFingerprint==='')throw new InvalidArgumentException('Allocation plan identity is required.');
  foreach($allocations as $item)if(!$item instanceof AllocationItem)throw new InvalidArgumentException('Invalid allocation item.');
 }
}
