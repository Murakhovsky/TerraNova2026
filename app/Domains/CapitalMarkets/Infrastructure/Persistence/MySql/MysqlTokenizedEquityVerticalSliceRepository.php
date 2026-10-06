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
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_opportunities
             WHERE organization_id=:org AND opportunity_id=:id LIMIT 1 FOR UPDATE'
        );
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

    public function saveExecutionPlan(string $organizationId,string $planId,string $opportunityId,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_execution_plans
             (organization_id,plan_id,opportunity_id,status,expires_at,payload_json)
             VALUES (:org,:id,:opportunity,:status,:expires_at,:payload)
             ON DUPLICATE KEY UPDATE status=VALUES(status),expires_at=VALUES(expires_at),payload_json=VALUES(payload_json)'
        )->execute([
            'org'=>$organizationId,'id'=>$planId,'opportunity'=>$opportunityId,
            'status'=>(string)($payload['status']??'CREATED'),
            'expires_at'=>$this->mysqlDate((string)($payload['expires_at']??'')),
            'payload'=>$this->json($payload),
        ]);
    }

    public function savePaperOrder(string $organizationId,string $orderId,string $executionId,string $legId,string $state,string $idempotencyKey,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_paper_orders
             (organization_id,order_id,execution_id,leg_id,state,idempotency_key,payload_json)
             VALUES (:org,:id,:execution,:leg,:state,:idempotency,:payload)
             ON DUPLICATE KEY UPDATE state=VALUES(state),payload_json=VALUES(payload_json)'
        )->execute([
            'org'=>$organizationId,'id'=>$orderId,'execution'=>$executionId,'leg'=>$legId,
            'state'=>$state,'idempotency'=>$idempotencyKey,'payload'=>$this->json($payload),
        ]);
    }

    public function savePaperFill(string $organizationId,string $fillId,string $orderId,string $executionId,string $idempotencyKey,array $payload):void
    {
        $this->connection->prepare(
            'INSERT IGNORE INTO tn_capital_market_paper_fills
             (organization_id,fill_id,order_id,execution_id,idempotency_key,payload_json)
             VALUES (:org,:id,:order_id,:execution,:idempotency,:payload)'
        )->execute([
            'org'=>$organizationId,'id'=>$fillId,'order_id'=>$orderId,'execution'=>$executionId,
            'idempotency'=>$idempotencyKey,'payload'=>$this->json($payload),
        ]);
    }

    public function savePosition(string $organizationId,string $positionId,string $portfolioId,string $strategyId,string $instrumentId,string $venueId,string $status,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_positions
             (organization_id,position_id,portfolio_id,strategy_id,instrument_id,venue_id,status,payload_json)
             VALUES (:org,:id,:portfolio,:strategy,:instrument,:venue,:status,:payload)
             ON DUPLICATE KEY UPDATE status=VALUES(status),payload_json=VALUES(payload_json)'
        )->execute([
            'org'=>$organizationId,'id'=>$positionId,'portfolio'=>$portfolioId,'strategy'=>$strategyId,
            'instrument'=>$instrumentId,'venue'=>$venueId,'status'=>$status,'payload'=>$this->json($payload),
        ]);
    }

    public function listPositions(string $organizationId,int $limit=500):array
    {
        $limit=max(1,min(5000,$limit));
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_positions
             WHERE organization_id=:org ORDER BY updated_at DESC,id DESC LIMIT '.$limit
        );
        $statement->execute(['org'=>$organizationId]);
        return array_map(fn(array $row):array=>$this->object((string)$row['payload_json']),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getExecutionForOpportunity(string $organizationId,string $opportunityId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_paper_executions
             WHERE organization_id=:org AND opportunity_id=:opportunity
             ORDER BY id ASC LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId,'opportunity'=>$opportunityId]);
        $json=$statement->fetchColumn();
        return is_string($json)?$this->object($json):null;
    }

    public function getExecution(string $organizationId,string $executionId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_paper_executions
             WHERE organization_id=:org AND execution_id=:id LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId,'id'=>$executionId]);
        $json=$statement->fetchColumn();
        return is_string($json)?$this->object($json):null;
    }

    public function listPaperOrdersForExecution(string $organizationId,string $executionId):array
    {
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_paper_orders
             WHERE organization_id=:org AND execution_id=:execution ORDER BY id ASC'
        );
        $statement->execute(['org'=>$organizationId,'execution'=>$executionId]);
        return array_map(fn(array $row):array=>$this->object((string)$row['payload_json']),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listPaperFillsForExecution(string $organizationId,string $executionId):array
    {
        $statement=$this->connection->prepare(
            'SELECT order_id,payload_json FROM tn_capital_market_paper_fills
             WHERE organization_id=:org AND execution_id=:execution ORDER BY id ASC'
        );
        $statement->execute(['org'=>$organizationId,'execution'=>$executionId]);
        return array_map(function(array $row):array{
            $payload=$this->object((string)$row['payload_json']);
            $payload['_order_id']=(string)$row['order_id'];
            return $payload;
        },$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function ledgerTransactionExists(string $organizationId,string $idempotencyKey):bool
    {
        $statement=$this->connection->prepare(
            'SELECT 1 FROM tn_capital_market_ledger_transactions
             WHERE organization_id=:org AND idempotency_key=:idempotency LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId,'idempotency'=>$idempotencyKey]);
        return $statement->fetchColumn()!==false;
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

    public function settlePaperExecution(
        string $organizationId,
        string $executionId,
        string $capitalReservationId,
        string $buyCashReservationId,
        string $sellInventoryReservationId,
        string $buyVenueId,
        string $buyInstrumentId,
        string $buyQuantity,
        string $sellVenueId,
        string $quoteAsset,
        string $sellCash,
        string $realizedPnl
    ):void{
        $ownsTransaction=!$this->connection->inTransaction();
        if($ownsTransaction)$this->connection->beginTransaction();
        try{
            $capital=$this->connection->prepare(
                'SELECT amount,status FROM tn_capital_market_capital_reservations
                 WHERE organization_id=:org AND reservation_id=:id FOR UPDATE'
            );
            $capital->execute(['org'=>$organizationId,'id'=>$capitalReservationId]);
            $capitalRow=$capital->fetch(PDO::FETCH_ASSOC);

            $balance=$this->connection->prepare(
                'SELECT reservation_id,venue_id,asset_key,amount,status
                 FROM tn_capital_market_paper_balance_reservations
                 WHERE organization_id=:org AND reservation_id IN (:buy_id,:sell_id) FOR UPDATE'
            );
            $balance->execute([
                'org'=>$organizationId,'buy_id'=>$buyCashReservationId,'sell_id'=>$sellInventoryReservationId,
            ]);
            $rows=$balance->fetchAll(PDO::FETCH_ASSOC);
            $byId=[];
            foreach($rows as $row)$byId[(string)$row['reservation_id']]=$row;
            $buyRow=$byId[$buyCashReservationId]??null;
            $sellRow=$byId[$sellInventoryReservationId]??null;

            if(is_array($capitalRow)&&$capitalRow['status']==='CONSUMED'
                &&is_array($buyRow)&&$buyRow['status']==='CONSUMED'
                &&is_array($sellRow)&&$sellRow['status']==='CONSUMED'){
                if($ownsTransaction)$this->connection->commit();
                return;
            }
            if(!is_array($capitalRow)||$capitalRow['status']!=='RESERVED'
                ||!is_array($buyRow)||$buyRow['status']!=='RESERVED'
                ||!is_array($sellRow)||$sellRow['status']!=='RESERVED'){
                throw new \DomainException('PAPER_EXECUTION_SETTLEMENT_STATE_INCONSISTENT');
            }

            foreach([$buyRow,$sellRow] as $row){
                $this->connection->prepare(
                    'UPDATE tn_capital_market_paper_balance_reservations SET status=\'CONSUMED\'
                     WHERE organization_id=:org AND reservation_id=:id AND status=\'RESERVED\''
                )->execute(['org'=>$organizationId,'id'=>(string)$row['reservation_id']]);
                $this->connection->prepare(
                    'UPDATE tn_capital_market_paper_balances
                     SET reserved_amount=reserved_amount-:amount
                     WHERE organization_id=:org AND venue_id=:venue AND asset_key=:asset'
                )->execute([
                    'amount'=>(string)$row['amount'],'org'=>$organizationId,
                    'venue'=>(string)$row['venue_id'],'asset'=>(string)$row['asset_key'],
                ]);
            }

            $this->connection->prepare(
                'INSERT INTO tn_capital_market_paper_balances
                 (organization_id,venue_id,asset_key,available_amount,reserved_amount)
                 VALUES (:org,:venue,:asset,:amount,0)
                 ON DUPLICATE KEY UPDATE available_amount=available_amount+VALUES(available_amount)'
            )->execute([
                'org'=>$organizationId,'venue'=>$buyVenueId,'asset'=>$buyInstrumentId,'amount'=>$buyQuantity,
            ]);
            $this->connection->prepare(
                'INSERT INTO tn_capital_market_paper_balances
                 (organization_id,venue_id,asset_key,available_amount,reserved_amount)
                 VALUES (:org,:venue,:asset,:amount,0)
                 ON DUPLICATE KEY UPDATE available_amount=available_amount+VALUES(available_amount)'
            )->execute([
                'org'=>$organizationId,'venue'=>$sellVenueId,'asset'=>$quoteAsset,'amount'=>$sellCash,
            ]);

            $this->connection->prepare(
                'UPDATE tn_capital_market_capital_reservations SET status=\'CONSUMED\'
                 WHERE organization_id=:org AND reservation_id=:id AND status=\'RESERVED\''
            )->execute(['org'=>$organizationId,'id'=>$capitalReservationId]);
            $this->connection->prepare(
                'UPDATE tn_capital_market_paper_portfolios
                 SET available_capital=available_capital+:amount+:pnl,
                     reserved_capital=reserved_capital-:amount,
                     realized_pnl=realized_pnl+:pnl
                 WHERE organization_id=:org'
            )->execute([
                'amount'=>(string)$capitalRow['amount'],'pnl'=>$realizedPnl,'org'=>$organizationId,
            ]);

            if($ownsTransaction)$this->connection->commit();
        }catch(\Throwable $error){
            if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
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


    public function saveHypothesisObservation(
        string $organizationId,
        string $observationId,
        string $hypothesis,
        string $stage,
        string $observedAt,
        string $fingerprint,
        array $payload
    ):void{
        $this->connection->prepare(
            'INSERT IGNORE INTO tn_capital_market_hypothesis_observations
             (organization_id,observation_id,hypothesis,stage,market_pair_id,candidate_id,opportunity_id,execution_id,
              detected,executable,realized,expected_pnl,realized_pnl,reason,fingerprint,payload_json,observed_at)
             VALUES
             (:org,:id,:hypothesis,:stage,:market_pair,:candidate,:opportunity,:execution,
              :detected,:executable,:realized,:expected_pnl,:realized_pnl,:reason,:fingerprint,:payload,:observed_at)'
        )->execute([
            'org'=>$organizationId,
            'id'=>$observationId,
            'hypothesis'=>$hypothesis,
            'stage'=>$stage,
            'market_pair'=>$payload['market_pair_id']??null,
            'candidate'=>$payload['candidate_id']??null,
            'opportunity'=>$payload['opportunity_id']??null,
            'execution'=>$payload['execution_id']??null,
            'detected'=>(int)(bool)($payload['detected']??false),
            'executable'=>(int)(bool)($payload['executable']??false),
            'realized'=>(int)(bool)($payload['realized']??false),
            'expected_pnl'=>(string)($payload['expected_pnl']??'0'),
            'realized_pnl'=>(string)($payload['realized_pnl']??'0'),
            'reason'=>isset($payload['reason'])?(string)$payload['reason']:null,
            'fingerprint'=>$fingerprint,
            'payload'=>$this->json($payload),
            'observed_at'=>$this->mysqlDate($observedAt),
        ]);
    }

    public function listHypothesisObservations(string $organizationId,?string $hypothesis=null,int $limit=10000):array
    {
        $limit=max(1,min(10000,$limit));
        $sql='SELECT observation_id,hypothesis,stage,market_pair_id,candidate_id,opportunity_id,execution_id,
                    detected,executable,realized,expected_pnl,realized_pnl,reason,fingerprint,observed_at,payload_json
              FROM tn_capital_market_hypothesis_observations
              WHERE organization_id=:org';
        $params=['org'=>$organizationId];
        if($hypothesis!==null){
            $sql.=' AND hypothesis=:hypothesis';
            $params['hypothesis']=$hypothesis;
        }
        $sql.=' ORDER BY observed_at ASC,id ASC LIMIT '.$limit;
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function(array $row):array{
            $payload=$this->object((string)$row['payload_json']);
            return array_replace($payload,[
                'observation_id'=>(string)$row['observation_id'],
                'hypothesis'=>(string)$row['hypothesis'],
                'stage'=>(string)$row['stage'],
                'market_pair_id'=>$row['market_pair_id'],
                'candidate_id'=>$row['candidate_id'],
                'opportunity_id'=>$row['opportunity_id'],
                'execution_id'=>$row['execution_id'],
                'detected'=>(bool)$row['detected'],
                'executable'=>(bool)$row['executable'],
                'realized'=>(bool)$row['realized'],
                'expected_pnl'=>(string)$row['expected_pnl'],
                'realized_pnl'=>(string)$row['realized_pnl'],
                'reason'=>$row['reason'],
                'fingerprint'=>(string)$row['fingerprint'],
                'observed_at'=>(string)$row['observed_at'],
            ]);
        },$rows);
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
            'hypothesis_observation_count'=>(int)$scalar('SELECT COUNT(*) FROM tn_capital_market_hypothesis_observations WHERE organization_id=:org'),
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
