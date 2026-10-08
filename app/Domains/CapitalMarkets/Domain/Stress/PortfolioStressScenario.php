<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Stress;
use InvalidArgumentException;
final readonly class PortfolioStressScenario {
 public function __construct(public string $id,public string $name,public array $shocks){if($id===''||$name===''||$shocks===[])throw new InvalidArgumentException('Stress scenario requires shocks.');}
}
