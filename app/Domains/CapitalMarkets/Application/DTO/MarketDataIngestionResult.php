<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\DTO;

use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;

final readonly class MarketDataIngestionResult
{
    public function __construct(
        public string $status,
        public bool $rawStored,
        public bool $canonicalStored,
        public bool $stateUpdated,
        public ?CanonicalMarketEvent $event=null,
        public ?MarketDataQualityAssessment $quality=null,
        public MarketState|ReferenceMarketState|null $state=null,
        public ?string $reason=null,
    ){}
}
