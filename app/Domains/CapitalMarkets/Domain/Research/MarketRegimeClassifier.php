<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;

final class MarketRegimeClassifier
{
    /** @param array<string,mixed> $evidence @param array<string,mixed> $thresholds */
    public function classify(array $evidence,array $thresholds=[]):MarketRegime
    {
        $liquidity=$this->decimal($evidence['liquidity_score']??null);
        $minLiquidity=$this->decimal($thresholds['low_liquidity_score_max']??null);
        if($liquidity!==null&&$minLiquidity!==null&&$liquidity->compareTo($minLiquidity)<=0){
            return MarketRegime::LowLiquidity;
        }

        $volatility=$this->decimal($evidence['volatility_bps']??null);
        $highVol=$this->decimal($thresholds['high_volatility_bps_min']??null);
        $lowVol=$this->decimal($thresholds['low_volatility_bps_max']??null);
        if($volatility!==null&&$highVol!==null&&$volatility->compareTo($highVol)>=0)return MarketRegime::HighVolatility;
        if($volatility!==null&&$lowVol!==null&&$volatility->compareTo($lowVol)<=0)return MarketRegime::LowVolatility;

        $funding=$this->abs($this->decimal($evidence['funding_rate']??$evidence['venue_a_rate']??null));
        $highFunding=$this->decimal($thresholds['high_funding_abs_min']??'0.0005');
        if($funding!==null&&$highFunding!==null&&$funding->compareTo($highFunding)>=0)return MarketRegime::HighFunding;

        $trend=$this->abs($this->decimal($evidence['trend_score']??null));
        $trendMin=$this->decimal($thresholds['trending_score_min']??null);
        if($trend!==null&&$trendMin!==null&&$trend->compareTo($trendMin)>=0)return MarketRegime::Trending;

        return MarketRegime::Normal;
    }

    private function decimal(mixed $value):?Decimal
    {
        if($value===null||$value==='')return null;
        if(!is_string($value)&&!is_int($value))return null;
        try{return Decimal::fromString((string)$value);}catch(\InvalidArgumentException){return null;}
    }

    private function abs(?Decimal $value):?Decimal
    {
        if($value===null)return null;
        return $value->isNegative()?Decimal::fromString(substr($value->value(),1)):$value;
    }
}
