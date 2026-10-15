<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Application\Contract\MarketDataProviderAvailabilityInterface;
use Domains\CapitalMarkets\Application\Service\CrossVenueMarketSourceAdmission;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;

$assert=static function(bool $good,string $reason):void {if (!$good)throw new RuntimeException($reason);};
$allowed=static fn(bool $enabled):MarketDataProviderAvailabilityInterface =>
    new class($enabled) implements MarketDataProviderAvailabilityInterface {
        public function __construct(private bool $enabled) {}
        public function enabled(string $organizationId,MarketSourceDescriptor $source):bool {
            return $this->enabled && $organizationId==='test-tenant';
        }
    };
$make=static fn(bool $enabled,string $licence,array $roles,string $mode):MarketSourceDescriptor =>
    new MarketSourceDescriptor(
        MarketSourceId::fromString('source-binance'),null,'binance.spot.rest',$enabled,1,
        $roles,null,new RateLimitPolicy(60,60),new ReconnectPolicy(),new MarketHealthPolicy(),
        ['data_mode'=>$mode],$licence,
    );
$trading=[MarketSourceRole::TradingSource];
$admission=new CrossVenueMarketSourceAdmission($allowed(true));
$assert($admission->reason('test-tenant',$make(false,'PUBLIC_READ_ONLY',$trading,'REST'),true)==='SOURCE_DISABLED',
    'Disabled source was admitted.');
$assert((new CrossVenueMarketSourceAdmission($allowed(false)))
    ->reason('test-tenant',$make(true,'PUBLIC_READ_ONLY',$trading,'REST'),true)==='PROVIDER_FEATURE_DISABLED',
    'Disabled provider feature was admitted.');
$assert($admission->reason('test-tenant',$make(true,'UNSPECIFIED',$trading,'REST'),true)==='LICENSE_PROFILE_UNDECLARED',
    'Missing source license profile was admitted.');
$assert($admission->reason('test-tenant',
    $make(true,'PUBLIC_READ_ONLY',[MarketSourceRole::HistoricalSource],'REST'),true)==='SOURCE_ROLE_NOT_APPROVED',
    'Historical-only source used as live BBO.');
$assert($admission->reason('test-tenant',$make(true,'PUBLIC_READ_ONLY',$trading,'STREAMING'),true)==='REST_MODE_NOT_EXPLICIT',
    'Streaming-owned source was admitted into REST writer.');
$assert($admission->reason('test-tenant',$make(true,'PUBLIC_READ_ONLY',$trading,'REST'),true)===null,
    'Explicit permitted REST source should be usable.');
$assert($admission->reason('test-tenant',$make(true,'PUBLIC_READ_ONLY',$trading,'STREAMING'))===null,
    'Ordinary subscription plan must not forbid streaming-owned source.');
echo "Cross-venue market source admission and REST/streaming segregation passed.\n";
