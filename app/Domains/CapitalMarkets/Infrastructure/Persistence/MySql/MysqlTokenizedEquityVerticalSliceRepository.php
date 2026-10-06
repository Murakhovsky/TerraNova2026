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
