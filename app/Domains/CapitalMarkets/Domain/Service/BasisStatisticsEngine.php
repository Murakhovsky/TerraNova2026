<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class BasisStatisticsEngine
{
    /** @param list<BasisObservation> $observations @return array<string,mixed> */
    public function summarize(array $observations):array
    {
        if($observations===[])return ['count'=>0,'mean_mid_basis_bps'=>'0','min_mid_basis_bps'=>'0','max_mid_basis_bps'=>'0'];
        foreach($observations as $o)if(!$o instanceof BasisObservation)throw new InvalidArgumentException('Basis statistics require typed observations.');
        $sum=Decimal::fromString('0');$min=$observations[0]->midBasisBps;$max=$min;
        foreach($observations as $o){
            $sum=DecimalMath::add($sum,$o->midBasisBps);
            if($o->midBasisBps->compareTo($min)<0)$min=$o->midBasisBps;
            if($o->midBasisBps->compareTo($max)>0)$max=$o->midBasisBps;
        }
        return [
            'count'=>count($observations),
            'mean_mid_basis_bps'=>DecimalMath::divide($sum,Decimal::fromString((string)count($observations)),12)->value(),
            'min_mid_basis_bps'=>$min->value(),'max_mid_basis_bps'=>$max->value(),
        ];
    }
}
