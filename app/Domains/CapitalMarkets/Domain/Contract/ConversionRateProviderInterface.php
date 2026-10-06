<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\Value\AssetCode;

interface ConversionRateProviderInterface
{
    public function getRate(AssetCode $sourceAsset,AssetCode $targetAsset,DateTimeImmutable $at):?ConversionRate;
}
