<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class PortfolioPerformanceAttributionEngine
{
    /** @param list<array<string,mixed>> $records */
    public function attribute(array $records):array
    {
        $dimensions=[
            'by_strategy'=>'strategy',
            'by_asset'=>'asset',
            'by_venue'=>'venue',
            'by_opportunity_type'=>'opportunity_type',
            'by_instrument_family'=>'instrument_family',
            'by_risk_bucket'=>'risk_bucket',
        ];
        $result=[
            'net_pnl'=>Decimal::fromString('0'),
            'deployed_capital'=>Decimal::fromString('0'),
            'risk_consumed'=>Decimal::fromString('0'),
        ];
        foreach(array_keys($dimensions) as $key)$result[$key]=[];

        foreach($records as $record){
            $pnl=Decimal::fromString((string)($record['pnl']??'0'));
            $capital=DecimalMath::abs(Decimal::fromString((string)($record['deployed_capital']??'0')));
            $risk=DecimalMath::abs(Decimal::fromString((string)($record['risk_consumed']??'0')));
            $result['net_pnl']=DecimalMath::add($result['net_pnl'],$pnl);
            $result['deployed_capital']=DecimalMath::add($result['deployed_capital'],$capital);
            $result['risk_consumed']=DecimalMath::add($result['risk_consumed'],$risk);

            foreach($dimensions as $target=>$source){
                $key=trim((string)($record[$source]??'UNKNOWN'));if($key==='')$key='UNKNOWN';
                if(!isset($result[$target][$key])){
                    $result[$target][$key]=[
                        'pnl'=>Decimal::fromString('0'),
                        'deployed_capital'=>Decimal::fromString('0'),
                        'risk_consumed'=>Decimal::fromString('0'),
                    ];
                }
                $result[$target][$key]['pnl']=DecimalMath::add($result[$target][$key]['pnl'],$pnl);
                $result[$target][$key]['deployed_capital']=DecimalMath::add($result[$target][$key]['deployed_capital'],$capital);
                $result[$target][$key]['risk_consumed']=DecimalMath::add($result[$target][$key]['risk_consumed'],$risk);
            }
        }

        $result['capital_efficiency']=$result['deployed_capital']->isZero()
            ?Decimal::fromString('0')
            :DecimalMath::divide($result['net_pnl'],$result['deployed_capital'],12);
        $result['risk_efficiency']=$result['risk_consumed']->isZero()
            ?Decimal::fromString('0')
            :DecimalMath::divide($result['net_pnl'],$result['risk_consumed'],12);

        return $result;
    }
}
