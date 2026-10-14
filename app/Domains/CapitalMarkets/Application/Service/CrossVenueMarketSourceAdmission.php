<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketDataProviderAvailabilityInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;

/**
 * Shared fail-closed source admission for pool subscriptions and REST fallback.
 * A source being configured does not imply provider/redistribution entitlement.
 */
final readonly class CrossVenueMarketSourceAdmission
{
    public function __construct(private MarketDataProviderAvailabilityInterface $availability) {}

    public function reason(string $org,MarketSourceDescriptor $source,bool $requireRest=false,bool $reference=false):?string
    {
        if ($org==='' || !$source->enabled) return 'SOURCE_DISABLED';
        if (!$this->availability->enabled($org,$source)) return 'PROVIDER_FEATURE_DISABLED';
        if ($source->licenseProfile==='UNSPECIFIED') return 'LICENSE_PROFILE_UNDECLARED';
        if ($reference ? !$source->hasRole(MarketSourceRole::ReferenceSource)
            : (!$source->hasRole(MarketSourceRole::TradingSource)
                && !$source->hasRole(MarketSourceRole::ValidationSource))) {
            return 'SOURCE_ROLE_NOT_APPROVED';
        }
        if ($requireRest && ($source->metadata['data_mode']??null)!=='REST') {
            return 'REST_MODE_NOT_EXPLICIT';
        }
        return null;
    }
}
