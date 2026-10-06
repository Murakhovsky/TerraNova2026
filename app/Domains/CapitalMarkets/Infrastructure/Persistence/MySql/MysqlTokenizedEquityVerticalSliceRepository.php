<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use PDO;

final readonly class MysqlTokenizedEquityVerticalSliceRepository implements TokenizedEquityVerticalSliceRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function saveCandidate(string $organizationId,string $candidateId,string $hypothesis,string $status,array $payload):void
    {
        $sql='INSERT INTO tn_capital_market_spread_candidates
            (organization_id,candidate_id,hypothesis,status,detected_at,expires_at,gross_edge_bps,payload_json)
            VALUES (:org,:id,:hypothesis,:status,:detected_at,:expires_at,:gross_edge_bps,:payload)
            ON DUPLICATE KEY UPDATE status=VALUES(status),expires_at=VALUES(expires_at),gross_edge_bps=VALUES(gross_edge_bps),payload_json=VALUES(payload_json)';
        $this->connection->prepare($sql)->execute([
            'org'=>$organizationId,'id'=>$candidateId,'hypothesis'=>$hypothesis,'status'=>$status,
            'detected_at'=>$this->mysqlDate((string)($payload['detected_at']??'')),
            'expires_at'=>$this->mysqlDate((string)($payload['expires_at']??'')),
            'gross_edge_bps'=>(string)($payload['gross_edge_bps']??'0'),
            'payload'=>$this->json($payload),
        ]);
    }

    public function saveOpportunity(string $organizationId,string $opportunityId,string $candidateId,string $hypothesis,string $status,array $payload):void
    {
        $sql='INSERT INTO tn_capital_market_opportunities
            (organization_id,opportunity_id,candidate_id,hypothesis,status,expected_net_edge_bps,expected_pnl,required_capital,expires_at,payload_json)
            VALUES (:org,:id,:candidate,:hypothesis,:status,:edge,:pnl,:capital,:expires_at,:payload)
            ON DUPLICATE KEY UPDATE status=VALUES(status),expected_net_edge_bps=VALUES(expected_net_edge_bps),
            expected_pnl=VALUES(expected_pnl),required_capital=VALUES(required_capital),expires_at=VALUES(expires_at),payload_json=VALUES(payload_json)';
        $this->connection->prepare($sql)->execute([
            'org'=>$organizationId,'id'=>$opportunityId,'candidate'=>$candidateId,'hypothesis'=>$hypothesis,'status'=>$status,
            'edge'=>(string)($payload['expected_net_edge_bps']??'0'),'pnl'=>(string)($payload['expected_pnl']??'0'),
            'capital'=>(string)($payload['required_capital']??'0'),'expires_at'=>$this->mysqlDate((string)($payload['expires_at']??'')),
            'payload'=>$this->json($payload),
        ]);
    }

    public function getOpportunity(string $organizationId,string $opportunityId):?array
    {
        $statement=$this->connection->prepare('SELECT payload_json FROM tn_capital_market_opportunities WHERE organization_id=:org AND opportunity_id=:id LIMIT 1');
        $statement->execute(['org'=>$organizationId,'id'=>$opportunityId]);
        $json=$statement->fetchColumn();
        return is_string($json)?$this->object($json):null;
    }

    public function saveRiskAssessment(string $organizationId,string $riskId,string $opportunityId,string $decision,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_risk_assessments
             (organization_id,risk_id,opportunity_id,decision,risk_score,payload_json)
             VALUES (:org,:id,:opportunity,:decision,:score,:payload)
             ON DUPLICATE KEY UPDATE decision=VALUES(decision),risk_score=VALUES(risk_score),payload_json=VALUES(payload_json)'
        )->execute([
            'org'=>$organizationId,'id'=>$riskId,'opportunity'=>$opportunityId,'decision'=>$decision,
            'score'=>(int)($payload['risk_score']??0),'payload'=>$this->json($payload),
        ]);
    }

    public function saveExecution(string $organizationId,string $executionId,string $opportunityId,string $status,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_paper_executions
             (organization_id,execution_id,opportunity_id,status,realized_pnl,edge_capture_ratio,payload_json)
             VALUES (:org,:id,:opportunity,:status,:pnl,:capture,:payload)
             ON DUPLICATE KEY UPDATE status=VALUES(status),realized_pnl=VALUES(realized_pnl),edge_capture_ratio=VALUES(edge_capture_ratio),payload_json=VALUES(payload_json)'
        )->execute([
            'org'=>$organizationId,'id'=>$executionId,'opportunity'=>$opportunityId,'status'=>$status,
            'pnl'=>(string)($payload['realized_pnl']??'0'),'capture'=>(string)($payload['edge_capture_ratio']??'0'),
            'payload'=>$this->json($payload),
        ]);
    }

    public function saveLedgerTransaction(string $organizationId,string $transactionId,string $idempotencyKey,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_ledger_transactions
             (organization_id,transaction_id,idempotency_key,payload_json)
             VALUES (:org,:id,:idempotency,:payload)'
        )->execute(['org'=>$organizationId,'id'=>$transactionId,'idempotency'=>$idempotencyKey,'payload'=>$this->json($payload)]);
    }

    public function initializePaperPortfolio(string $organizationId,string $currency,string $initialCapital):array
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_paper_portfolios
             (organization_id,currency,initial_capital,available_capital,reserved_capital,realized_pnl)
             VALUES (:org,:currency,:capital,:capital,0,0)
             ON DUPLICATE KEY UPDATE currency=VALUES(currency),initial_capital=VALUES(initial_capital),
             available_capital=VALUES(initial_capital),reserved_capital=0,realized_pnl=0'
        )->execute(['org'=>$organizationId,'currency'=>$currency,'capital'=>$initialCapital]);
        return $this->paperPortfolio($organizationId)??[];
    }

    public function paperPortfolio(string $organizationId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT currency,initial_capital,available_capital,reserved_capital,realized_pnl,updated_at
             FROM tn_capital_market_paper_portfolios WHERE organization_id=:org LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function reserveCapital(
        string $organizationId,string $reservationId,string $opportunityId,string $amount,string $expiresAt
    ):bool{
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $reserve=$this->connection->prepare(
                'UPDATE tn_capital_market_paper_portfolios
                 SET available_capital=available_capital-:amount,reserved_capital=reserved_capital+:amount
                 WHERE organization_id=:org AND available_capital>=:amount'
            );
            $reserve->execute(['amount'=>$amount,'org'=>$organizationId]);
            if($reserve->rowCount()!==1){
                if($ownsTransaction)$this->connection->rollBack();
                return false;
            }
            $this->connection->prepare(
                'INSERT INTO tn_capital_market_capital_reservations
                 (organization_id,reservation_id,opportunity_id,amount,status,expires_at)
                 VALUES (:org,:id,:opportunity,:amount,\'RESERVED\',:expires_at)'
            )->execute([
                'org'=>$organizationId,'id'=>$reservationId,'opportunity'=>$opportunityId,'amount'=>$amount,
                'expires_at'=>$this->mysqlDate($expiresAt),
            ]);
            if($ownsTransaction)$this->connection->commit();
            return true;
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function releaseReservation(string $organizationId,string $reservationId):void
    {
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $select=$this->connection->prepare(
                'SELECT amount,status FROM tn_capital_market_capital_reservations
                 WHERE organization_id=:org AND reservation_id=:id FOR UPDATE'
            );
            $select->execute(['org'=>$organizationId,'id'=>$reservationId]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)&&$row['status']==='RESERVED'){
                $this->connection->prepare(
                    'UPDATE tn_capital_market_capital_reservations SET status=\'RELEASED\'
                     WHERE organization_id=:org AND reservation_id=:id AND status=\'RESERVED\''
                )->execute(['org'=>$organizationId,'id'=>$reservationId]);
                $this->connection->prepare(
                    'UPDATE tn_capital_market_paper_portfolios
                     SET available_capital=available_capital+:amount,reserved_capital=reserved_capital-:amount
                     WHERE organization_id=:org'
                )->execute(['amount'=>(string)$row['amount'],'org'=>$organizationId]);
            }
            if($ownsTransaction)$this->connection->commit();
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function completeReservation(string $organizationId,string $reservationId,string $realizedPnl):void
    {
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $select=$this->connection->prepare(
                'SELECT amount,status FROM tn_capital_market_capital_reservations
                 WHERE organization_id=:org AND reservation_id=:id FOR UPDATE'
            );
            $select->execute(['org'=>$organizationId,'id'=>$reservationId]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['status']!=='RESERVED')throw new \DomainException('CAPITAL_RESERVATION_NOT_ACTIVE');
            $this->connection->prepare(
                'UPDATE tn_capital_market_capital_reservations SET status=\'CONSUMED\'
                 WHERE organization_id=:org AND reservation_id=:id'
            )->execute(['org'=>$organizationId,'id'=>$reservationId]);
            $this->connection->prepare(
                'UPDATE tn_capital_market_paper_portfolios
                 SET available_capital=available_capital+:amount+:pnl,
                     reserved_capital=reserved_capital-:amount,
                     realized_pnl=realized_pnl+:pnl
                 WHERE organization_id=:org'
            )->execute(['amount'=>(string)$row['amount'],'pnl'=>$realizedPnl,'org'=>$organizationId]);
            if($ownsTransaction)$this->connection->commit();
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function setPaperBalance(string $organizationId,string $venueId,string $assetKey,string $amount):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_paper_balances
             (organization_id,venue_id,asset_key,available_amount,reserved_amount)
             VALUES (:org,:venue,:asset,:amount,0)
             ON DUPLICATE KEY UPDATE available_amount=VALUES(available_amount),reserved_amount=0'
        )->execute(['org'=>$organizationId,'venue'=>$venueId,'asset'=>$assetKey,'amount'=>$amount]);
    }

    public function listPaperBalances(string $organizationId):array
    {
        $statement=$this->connection->prepare(
            'SELECT venue_id,asset_key,available_amount,reserved_amount,updated_at
             FROM tn_capital_market_paper_balances WHERE organization_id=:org ORDER BY venue_id,asset_key'
        );
        $statement->execute(['org'=>$organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reservePaperBalance(
        string $organizationId,string $reservationId,string $opportunityId,
        string $venueId,string $assetKey,string $amount,string $expiresAt
    ):bool{
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $reserve=$this->connection->prepare(
                'UPDATE tn_capital_market_paper_balances
                 SET available_amount=available_amount-:amount,reserved_amount=reserved_amount+:amount
                 WHERE organization_id=:org AND venue_id=:venue AND asset_key=:asset AND available_amount>=:amount'
            );
            $reserve->execute(['amount'=>$amount,'org'=>$organizationId,'venue'=>$venueId,'asset'=>$assetKey]);
            if($reserve->rowCount()!==1){
                if($ownsTransaction)$this->connection->rollBack();
                return false;
            }
            $this->connection->prepare(
                'INSERT INTO tn_capital_market_paper_balance_reservations
                 (organization_id,reservation_id,opportunity_id,venue_id,asset_key,amount,status,expires_at)
                 VALUES (:org,:id,:opportunity,:venue,:asset,:amount,\'RESERVED\',:expires_at)'
            )->execute([
                'org'=>$organizationId,'id'=>$reservationId,'opportunity'=>$opportunityId,'venue'=>$venueId,
                'asset'=>$assetKey,'amount'=>$amount,'expires_at'=>$this->mysqlDate($expiresAt),
            ]);
            if($ownsTransaction)$this->connection->commit();
            return true;
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function releasePaperBalanceReservation(string $organizationId,string $reservationId):void
    {
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $select=$this->connection->prepare(
                'SELECT venue_id,asset_key,amount,status FROM tn_capital_market_paper_balance_reservations
                 WHERE organization_id=:org AND reservation_id=:id FOR UPDATE'
            );
            $select->execute(['org'=>$organizationId,'id'=>$reservationId]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)&&$row['status']==='RESERVED'){
                $this->connection->prepare(
                    'UPDATE tn_capital_market_paper_balance_reservations SET status=\'RELEASED\'
                     WHERE organization_id=:org AND reservation_id=:id AND status=\'RESERVED\''
                )->execute(['org'=>$organizationId,'id'=>$reservationId]);
                $this->connection->prepare(
                    'UPDATE tn_capital_market_paper_balances
                     SET available_amount=available_amount+:amount,reserved_amount=reserved_amount-:amount
                     WHERE organization_id=:org AND venue_id=:venue AND asset_key=:asset'
                )->execute([
                    'amount'=>(string)$row['amount'],'org'=>$organizationId,
                    'venue'=>(string)$row['venue_id'],'asset'=>(string)$row['asset_key'],
                ]);
            }
            if($ownsTransaction)$this->connection->commit();
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function consumePaperBalanceReservation(string $organizationId,string $reservationId):void
    {
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $select=$this->connection->prepare(
                'SELECT venue_id,asset_key,amount,status FROM tn_capital_market_paper_balance_reservations
                 WHERE organization_id=:org AND reservation_id=:id FOR UPDATE'
            );
            $select->execute(['org'=>$organizationId,'id'=>$reservationId]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['status']!=='RESERVED')throw new \DomainException('PAPER_BALANCE_RESERVATION_NOT_ACTIVE');
            $this->connection->prepare(
                'UPDATE tn_capital_market_paper_balance_reservations SET status=\'CONSUMED\'
                 WHERE organization_id=:org AND reservation_id=:id'
            )->execute(['org'=>$organizationId,'id'=>$reservationId]);
            $this->connection->prepare(
                'UPDATE tn_capital_market_paper_balances SET reserved_amount=reserved_amount-:amount
                 WHERE organization_id=:org AND venue_id=:venue AND asset_key=:asset'
            )->execute([
                'amount'=>(string)$row['amount'],'org'=>$organizationId,
                'venue'=>(string)$row['venue_id'],'asset'=>(string)$row['asset_key'],
            ]);
            if($ownsTransaction)$this->connection->commit();
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function creditPaperBalance(string $organizationId,string $venueId,string $assetKey,string $amount):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_paper_balances
             (organization_id,venue_id,asset_key,available_amount,reserved_amount)
             VALUES (:org,:venue,:asset,:amount,0)
             ON DUPLICATE KEY UPDATE available_amount=available_amount+VALUES(available_amount)'
        )->execute(['org'=>$organizationId,'venue'=>$venueId,'asset'=>$assetKey,'amount'=>$amount]);
    }

    public function saveHypothesisObservation(
        string $organizationId,string $observationId,string $hypothesis,string $status,array $payload
    ):void{
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_hypothesis_observations
             (organization_id,observation_id,hypothesis,status,candidate_count,opportunity_count,executable_count,
              best_net_edge_bps,best_expected_pnl,observed_at,payload_json)
             VALUES (:org,:id,:hypothesis,:status,:candidates,:opportunities,:executable,:edge,:pnl,:observed_at,:payload)
             ON DUPLICATE KEY UPDATE status=VALUES(status),candidate_count=VALUES(candidate_count),
             opportunity_count=VALUES(opportunity_count),executable_count=VALUES(executable_count),
             best_net_edge_bps=VALUES(best_net_edge_bps),best_expected_pnl=VALUES(best_expected_pnl),payload_json=VALUES(payload_json)'
        )->execute([
            'org'=>$organizationId,'id'=>$observationId,'hypothesis'=>$hypothesis,'status'=>$status,
            'candidates'=>(int)($payload['candidate_count']??0),
            'opportunities'=>(int)($payload['opportunity_count']??0),
            'executable'=>(int)($payload['executable_count']??0),
            'edge'=>$payload['best_net_edge_bps']??null,
            'pnl'=>$payload['best_expected_pnl']??null,
            'observed_at'=>$this->mysqlDate((string)($payload['observed_at']??'')),
            'payload'=>$this->json($payload),
        ]);
    }

    public function researchMetrics(string $organizationId,string $hypothesis):array
    {
        $scalar=function(string $sql)use($organizationId,$hypothesis):string{
            $statement=$this->connection->prepare($sql);
            $statement->execute(['org'=>$organizationId,'hypothesis'=>$hypothesis]);
            return (string)$statement->fetchColumn();
        };
        return [
            'observation_count'=>(int)$scalar(
                'SELECT COUNT(*) FROM tn_capital_market_hypothesis_observations
                 WHERE organization_id=:org AND hypothesis=:hypothesis'
            ),
            'detected_count'=>(int)$scalar(
                'SELECT COUNT(*) FROM tn_capital_market_spread_candidates
                 WHERE organization_id=:org AND hypothesis=:hypothesis'
            ),
            'executable_count'=>(int)$scalar(
                'SELECT COUNT(*) FROM tn_capital_market_opportunities
                 WHERE organization_id=:org AND hypothesis=:hypothesis AND status=\'APPROVED\''
            ),
            'realized_count'=>(int)$scalar(
                'SELECT COUNT(*) FROM tn_capital_market_paper_executions e
                 INNER JOIN tn_capital_market_opportunities o
                   ON o.organization_id=e.organization_id AND o.opportunity_id=e.opportunity_id
                 WHERE e.organization_id=:org AND o.hypothesis=:hypothesis AND e.status=\'COMPLETED\''
            ),
            'total_realized_pnl'=>$scalar(
                'SELECT COALESCE(SUM(e.realized_pnl),0) FROM tn_capital_market_paper_executions e
                 INNER JOIN tn_capital_market_opportunities o
                   ON o.organization_id=e.organization_id AND o.opportunity_id=e.opportunity_id
                 WHERE e.organization_id=:org AND o.hypothesis=:hypothesis AND e.status=\'COMPLETED\''
            ),
            'average_edge_capture_ratio'=>$scalar(
                'SELECT COALESCE(AVG(e.edge_capture_ratio),0) FROM tn_capital_market_paper_executions e
                 INNER JOIN tn_capital_market_opportunities o
                   ON o.organization_id=e.organization_id AND o.opportunity_id=e.opportunity_id
                 WHERE e.organization_id=:org AND o.hypothesis=:hypothesis AND e.status=\'COMPLETED\''
            ),
        ];
    }

    public function listOpportunities(string $organizationId,int $limit=200):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_opportunities WHERE organization_id=:org ORDER BY created_at DESC LIMIT '.$limit
        );
        $statement->execute(['org'=>$organizationId]);
        return array_map(fn(array $row):array=>$this->object((string)$row['payload_json']),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function dashboard(string $organizationId):array
    {
        $scalar=function(string $sql)use($organizationId):string{
            $s=$this->connection->prepare($sql);$s->execute(['org'=>$organizationId]);return (string)$s->fetchColumn();
        };
        return [
            'candidate_count'=>(int)$scalar('SELECT COUNT(*) FROM tn_capital_market_spread_candidates WHERE organization_id=:org'),
            'opportunity_count'=>(int)$scalar('SELECT COUNT(*) FROM tn_capital_market_opportunities WHERE organization_id=:org'),
            'positive_net_opportunity_count'=>(int)$scalar('SELECT COUNT(*) FROM tn_capital_market_opportunities WHERE organization_id=:org AND expected_pnl>0'),
            'paper_execution_count'=>(int)$scalar('SELECT COUNT(*) FROM tn_capital_market_paper_executions WHERE organization_id=:org'),
            'paper_realized_pnl'=>$scalar('SELECT COALESCE(SUM(realized_pnl),0) FROM tn_capital_market_paper_executions WHERE organization_id=:org AND status=\'COMPLETED\''),
        ];
    }

    private function json(array $payload):string{return json_encode($payload,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);}
    private function object(string $json):array{$v=json_decode($json,true,flags:JSON_THROW_ON_ERROR);return is_array($v)&&!array_is_list($v)?$v:[];}
    private function mysqlDate(string $value):string
    {
        $date=new \DateTimeImmutable($value);
        return $date->format('Y-m-d H:i:s.u');
    }
}
