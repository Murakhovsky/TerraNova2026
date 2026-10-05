<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\AssetCode;

final class MarketComparability
{
    public function compare(
        MarketState $market,
        ReferenceMarketState $reference,
        AssetCode $marketQuote,
        AssetCode $referenceQuote,
        ?ConversionRate $conversion=null,
    ):MarketComparabilityStatus{
        if($market->quality->trustStatus!==MarketTrustStatus::Trusted||$reference->quality->trustStatus!==MarketTrustStatus::Trusted){
            return $reference->quality->trustStatus===MarketTrustStatus::Stale
                ?MarketComparabilityStatus::ReferenceStale
                :MarketComparabilityStatus::Untrusted;
        }
        if($market->marketStatus!==$reference->marketStatus
            &&$reference->marketStatus===MarketStatus::Closed
            &&$market->marketStatus===MarketStatus::Open){
            return MarketComparabilityStatus::SessionMismatch;
        }
        if(!$marketQuote->equals($referenceQuote)){
            if($conversion===null||$conversion->quality!==MarketQualityStatus::Trusted){
                return MarketComparabilityStatus::CrossCurrency;
            }
            $direct=$conversion->sourceAsset->equals($marketQuote)&&$conversion->targetAsset->equals($referenceQuote);
            $inverse=$conversion->sourceAsset->equals($referenceQuote)&&$conversion->targetAsset->equals($marketQuote);
            if(!$direct&&!$inverse)return MarketComparabilityStatus::CrossCurrency;
        }
        if($market->quote===null||$reference->quote===null)return MarketComparabilityStatus::InsufficientData;
        return MarketComparabilityStatus::Comparable;
    }
}
