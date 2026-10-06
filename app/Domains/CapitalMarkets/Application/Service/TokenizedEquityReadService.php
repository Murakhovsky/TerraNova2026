<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Observability\CapitalMarketsAlertType;

final readonly class TokenizedEquityReadService
{
    public function __construct(
        private TokenizedEquityVerticalSliceRepositoryInterface $repository,
        private TokenizedEquityTelemetry $telemetry,
    ){}

    /** @return list<array<string,mixed>> */
    public function positions(string $organizationId,int $limit=500):array
    {
        return $this->repository->listPositions($organizationId,$limit);
    }

    /** @return list<array<string,mixed>> */
    public function executions(string $organizationId,int $limit=500):array
    {
        return $this->repository->listExecutions($organizationId,$limit);
    }

    /** @return list<array<string,mixed>> */
    public function ledger(string $organizationId,int $limit=500):array
    {
        return $this->repository->listLedgerTransactions($organizationId,$limit);
    }

    /** @return list<array<string,mixed>> */
    public function observations(string $organizationId,?string $hypothesis=null,int $limit=1000):array
    {
        $code=$hypothesis===null?null:strtoupper(trim($hypothesis));
        if($code!==null&&!in_array($code,['H1','H2'],true))throw new \InvalidArgumentException('hypothesis must be H1 or H2.');
        return $this->repository->listHypothesisObservations($organizationId,$code,$limit);
    }

    /** @return array<string,mixed> */
    public function performance(string $organizationId,int $limit=1000):array
    {
        $executions=$this->repository->listExecutions($organizationId,$limit);
        $attempts=count($executions);
        $completed=0;$compensated=0;$invalidated=0;
        $realized=Decimal::fromString('0');
        $capture=Decimal::fromString('0');
        foreach($executions as $execution){
            $status=(string)($execution['status']??'');
            if($status==='COMPLETED'||$status==='COMPLETED_COMPENSATED')$completed++;
            if($status==='COMPLETED_COMPENSATED')$compensated++;
            if($status==='INVALIDATED')$invalidated++;
            $realized=DecimalMath::add($realized,Decimal::fromString((string)($execution['realized_pnl']??'0')));
            $capture=DecimalMath::add($capture,Decimal::fromString((string)($execution['edge_capture_ratio']??'0')));
        }
        $denominator=Decimal::fromString((string)max(1,$attempts));
        return [
            'execution_attempts'=>$attempts,
            'completed'=>$completed,
            'compensated'=>$compensated,
            'invalidated'=>$invalidated,
            'completion_rate'=>$attempts===0?'0':DecimalMath::divide(Decimal::fromString((string)$completed),$denominator,12)->value(),
            'compensation_rate'=>$attempts===0?'0':DecimalMath::divide(Decimal::fromString((string)$compensated),$denominator,12)->value(),
            'realized_pnl'=>$realized->value(),
            'average_edge_capture_ratio'=>$attempts===0?'0':DecimalMath::divide($capture,$denominator,12)->value(),
        ];
    }

    /** @return array<string,mixed> */
    public function reconcile(string $organizationId):array
    {
        $portfolio=$this->repository->paperPortfolio($organizationId);
        $balances=$this->repository->listPaperBalances($organizationId);
        $positions=$this->repository->listPositions($organizationId,5000);
        $executions=$this->repository->listExecutions($organizationId,5000);

        $negativeBalances=array_values(array_filter($balances,static fn(array $b):bool=>
            Decimal::fromString((string)($b['available_amount']??'0'))->isNegative()
            ||Decimal::fromString((string)($b['reserved_amount']??'0'))->isNegative()
        ));
        $unsettled=array_values(array_filter($executions,static fn(array $e):bool=>
            in_array((string)($e['status']??''),['READY','PARTIALLY_EXECUTED','EXECUTING','COMPENSATING'],true)
        ));

        $issues=[];
        if($portfolio!==null&&(
            Decimal::fromString((string)($portfolio['available_capital']??'0'))->isNegative()
            ||Decimal::fromString((string)($portfolio['reserved_capital']??'0'))->isNegative()
        ))$issues[]='NEGATIVE_PORTFOLIO_CAPITAL';
        if($negativeBalances!==[])$issues[]='NEGATIVE_VENUE_BALANCE';
        if($unsettled!==[])$issues[]='UNSETTLED_EXECUTION';

        $ok=$issues===[];
        $this->telemetry->metric($organizationId,'reconciliation_runs_total',1.0,['ok'=>$ok]);
        if(!$ok){
            $this->telemetry->metric($organizationId,'reconciliation_failures_total',1.0,['issue_count'=>count($issues)]);
            $this->telemetry->alert($organizationId,CapitalMarketsAlertType::PositionReconciliationError,[
                'issues'=>$issues,
                'unsettled_execution_count'=>count($unsettled),
                'negative_balance_count'=>count($negativeBalances),
            ]);
        }

        return [
            'ok'=>$ok,
            'issues'=>$issues,
            'portfolio'=>$portfolio,
            'venue_balance_count'=>count($balances),
            'position_count'=>count($positions),
            'unsettled_execution_count'=>count($unsettled),
            'negative_balance_count'=>count($negativeBalances),
        ];
    }
}
