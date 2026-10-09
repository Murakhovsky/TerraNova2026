<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Throwable;

/** Paper-only NAV windows. Never converts simulation to certified accounting. */
final class PaperNavWindowProjector
{
    /** @param list<array<string,mixed>> $snapshots @return array<string,mixed> */
    public static function project(array $snapshots,?DateTimeImmutable $at=null,int $maxSkewSeconds=900):array
    {
        $now=($at??new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $rows=[];$rejected=[];
        foreach ($snapshots as $snapshot) {
            $time=null;
            if (is_array($snapshot) && is_string($snapshot['valued_at']??null)) {
                try {
                    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/',$snapshot['valued_at'])===1) {
                        $time=(new DateTimeImmutable($snapshot['valued_at']))
                            ->setTimezone(new DateTimeZone('UTC'));
                    }
                } catch (Throwable) {}
            }
            if ($time===null || !is_array($snapshot)
                || ($snapshot['status']??null)!=='SIMULATED'
                || ($snapshot['valuation_status']??null)!=='SIMULATED'
                || ($snapshot['mode']??null)!=='PAPER'
                || ($snapshot['certified']??null)!==false
                || ($snapshot['ledger_reconciled']??null)!==false
                || ($snapshot['external_flows_reconciled']??null)!==false
                || !is_string($snapshot['source_fingerprint']??null)
                || preg_match('/^[a-f0-9]{64}$/',$snapshot['source_fingerprint'])!==1) {
                $rejected[]=$time;continue;
            }
            try {
                $equity=Decimal::fromString((string)($snapshot['equity']??''));
                $flow=Decimal::fromString((string)($snapshot['cumulative_external_net_flow']??''));
                $capital=Decimal::fromString((string)($snapshot['initial_capital']??''));
                $currency=strtoupper(trim((string)($snapshot['currency']??'')));
                if ($equity->isNegative() || $capital->isNegative() || $currency==='') {
                    $rejected[]=$time;continue;
                }
                if ($time<=$now) $rows[]=['time'=>$time,'equity'=>$equity,'flow'=>$flow,
                    'initial'=>$capital,'currency'=>$currency,'fingerprint'=>$snapshot['source_fingerprint']];
            } catch (Throwable) {$rejected[]=$time;}
        }
        usort($rows,static fn(array $a,array $b):int=>$a['time']<=>$b['time']);
        $latest=['status'=>'UNAVAILABLE','mode'=>'PAPER','equity'=>null,'currency'=>null,
            'valued_at'=>null,'reason'=>'NO_RECENT_SIMULATED_SNAPSHOT'];
        $fresh=array_values(array_filter($rows,static fn(array $x):bool=>
            $now->getTimestamp()-$x['time']->getTimestamp()<=$maxSkewSeconds));
        $recentRejected=false;
        foreach ($rejected as $time) {
            if ($time===null || ($time<=$now && $now->getTimestamp()-$time->getTimestamp()<=$maxSkewSeconds)) {
                $recentRejected=true;break;
            }
        }
        if ($recentRejected) $latest['reason']='INVALID_RECENT_SIMULATED_SNAPSHOT';
        elseif ($fresh!==[]) {
            $current=end($fresh);
            $duplicates=0;
            foreach ($fresh as $x) if ($x['time']==$current['time']) $duplicates++;
            if ($duplicates>1) $latest['reason']='CONFLICTING_SIMULATED_SNAPSHOTS';
            else {
                $latest=['status'=>'SIMULATED','mode'=>'PAPER','equity'=>$current['equity']->value(),
                    'currency'=>$current['currency'],'valued_at'=>$current['time']->format(DATE_ATOM),'reason'=>null];
            }
        }
        $windows=[];
        $starts=[
            'today'=>new DateTimeImmutable($now->format('Y-m-d').' 00:00:00',new DateTimeZone('UTC')),
            '30d'=>$now->modify('-30 days'),
        ];
        foreach ($starts as $key=>$start) {
            $base=['status'=>'UNAVAILABLE','mode'=>'PAPER','net_pnl'=>null,'currency'=>null,
                'reason'=>'MISSING_PAPER_WINDOW_BOUNDARY','from_utc'=>$start->format(DATE_ATOM),
                'to_utc'=>$now->format(DATE_ATOM),'scope'=>'SIMULATED_PORTFOLIO_NAV'];
            foreach ($rejected as $time) {
                if ($time===null || ($time>=$start && $time<=$now)) {
                    $base['reason']='INVALID_SIMULATED_SNAPSHOT_IN_WINDOW';break;
                }
            }
            if ($base['reason']==='INVALID_SIMULATED_SNAPSHOT_IN_WINDOW') {$windows[$key]=$base;continue;}
            $opening=null;$closing=null;
            foreach ($rows as $row) {
                if ($row['time']<=$start) $opening=$row;
                if ($row['time']<=$now) $closing=$row;
            }
            if ($opening===null || $closing===null) {$windows[$key]=$base;continue;}
            $startLag=$start->getTimestamp()-$opening['time']->getTimestamp();
            $endLag=$now->getTimestamp()-$closing['time']->getTimestamp();
            if ($opening['time']>=$closing['time']
                || $startLag>$maxSkewSeconds || $endLag>$maxSkewSeconds) {
                $windows[$key]=[...$base,'reason'=>'PAPER_WINDOW_BOUNDARY_STALE_OR_INSUFFICIENT'];continue;
            }
            $seen=[];$inconsistent=false;
            foreach ($rows as $row) {
                if ($row['time']<$opening['time']||$row['time']>$closing['time']) continue;
                $stamp=$row['time']->format('Y-m-d H:i:s.u');
                if (isset($seen[$stamp]) || $row['currency']!==$opening['currency']
                    || $row['initial']->compareTo($opening['initial'])!==0) $inconsistent=true;
                $seen[$stamp]=true;
            }
            if ($inconsistent) {
                $windows[$key]=[...$base,'reason'=>'PAPER_NAV_EPOCH_CURRENCY_OR_TIME_CONFLICT'];continue;
            }
            $flowDelta=DecimalMath::subtract($closing['flow'],$opening['flow']);
            $pnl=DecimalMath::subtract(
                DecimalMath::subtract($closing['equity'],$opening['equity']),$flowDelta,
            );
            $windows[$key]=[...$base,'status'=>'SIMULATED','net_pnl'=>$pnl->value(),
                'currency'=>$closing['currency'],'reason'=>null,
                'opening_valued_at'=>$opening['time']->format(DATE_ATOM),
                'closing_valued_at'=>$closing['time']->format(DATE_ATOM),
                'external_flow_delta'=>$flowDelta->value()];
        }
        return ['latest'=>$latest,'windows'=>$windows];
    }
}
