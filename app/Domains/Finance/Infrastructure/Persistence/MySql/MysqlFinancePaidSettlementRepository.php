<?php
declare(strict_types=1);
namespace Domains\Finance\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Finance\Application\Contract\FinancePaidSettlementStore;
use Domains\Finance\Domain\BudgetControlPolicy;
use Domains\Finance\Domain\FinancePaidSettlementPolicy;
use PDO;
use Throwable;

/** Private, atomic budget settlement. No provider write, payment or accounting Expense. */
final readonly class MysqlFinancePaidSettlementRepository implements FinancePaidSettlementStore
{
    public function __construct(private PDO $connection,private FinancePaidSettlementPolicy $policy){}
    public function receipt(string $org,string $reservationId):?array
    {
        return $this->readReceipt($org,$reservationId,false);
    }
    private function readReceipt(string $org,string $reservationId,bool $locked):?array
    {
        $row=$this->one('SELECT reservation_id,envelope_id,action_id,actor_id,candidate_hash,currency,reserved_minor,committed_minor,released_minor,created_at
            FROM cos_finance_paid_settlements WHERE organization_id=? AND reservation_id=?'.($locked?' FOR UPDATE':''),[$org,$reservationId]);
        if($row===null)return null;
        foreach(['reserved_minor','committed_minor','released_minor'] as $field)$row[$field]=(int)$row[$field];
        return [...$row,'state'=>'SETTLED','finance_settled'=>true,'expense_confirmed'=>false,'payment_confirmed'=>false];
    }

    public function settle(string $org,string $reservationId,string $actor,string $action,string $trace,
        int $expectedRevision,string $candidateHash,array|callable $evidence):array
    {
        BudgetControlPolicy::id($reservationId,'finr-');BudgetControlPolicy::revision($expectedRevision);
        if($org===''||strlen($org)>190||!preg_match('/^[1-9][0-9]{0,18}$/D',$actor)
            ||!preg_match('/^[a-f0-9]{32}$/D',$action)||!preg_match('/^[a-f0-9]{64}$/D',$candidateHash)
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$trace)||$this->connection->inTransaction()){
            throw new DomainException('Invalid private Finance settlement transaction.');
        }
        $this->connection->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->connection->beginTransaction();
        try{
            // Shared lock order with reserve, release and exposure claim.
            $lookup=$this->one('SELECT envelope_id FROM cos_finance_budget_reservations WHERE organization_id=? AND id=?',[$org,$reservationId]);
            $id=$lookup['envelope_id']??null;if(!is_string($id))throw new DomainException('Reservation unavailable.');
            $envelope=$this->one('SELECT * FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=? FOR UPDATE',[$org,$id]);
            $reservation=$this->one('SELECT * FROM cos_finance_budget_reservations WHERE organization_id=? AND id=? FOR UPDATE',[$org,$reservationId]);
            $claim=$this->one('SELECT * FROM cos_finance_paid_exposure_claims WHERE organization_id=? AND reservation_id=? FOR UPDATE',[$org,$reservationId]);
            if($envelope===null||$reservation===null||$claim===null||$claim['envelope_id']!==$id||$reservation['envelope_id']!==$id)
                throw new DomainException('Native exposure and budget binding required.');
            $claim['amount_minor']=(int)$claim['amount_minor'];
            $existing=$this->readReceipt($org,$reservationId,true);
            if($existing!==null){
                if($existing['action_id']!==$action||$existing['actor_id']!==$actor||$existing['candidate_hash']!==$candidateHash
                    ||$reservation['state']!=='SETTLED')throw new DomainException('Settlement already committed with different authority.');
                $this->connection->commit();return [...$existing,'fresh'=>false];
            }
            if(is_callable($evidence))$evidence=$evidence();
            foreach(['plan','terminal','observation','document'] as $field)
                if(!is_array($evidence[$field]??null))throw new DomainException('Missing native settlement evidence.');
            $candidate=$this->policy->prepare($org,$claim,$evidence['plan'],$evidence['terminal'],$evidence['observation'],$evidence['document']);
            if(!hash_equals($candidateHash,$candidate['candidate_hash']))throw new DomainException('Evidence changed after settlement approval.');
            $cap=$candidate['reserved_minor'];$amount=$candidate['proposed_commit_minor'];$released=$candidate['proposed_release_minor'];
            if((int)$envelope['revision']!==$expectedRevision||!in_array($envelope['state'],['ACTIVE','FROZEN'],true)
                ||$reservation['state']!=='RESERVED'||$envelope['source_domain']!=='marketing'||$reservation['source_domain']!=='marketing'
                ||$envelope['source_ref']!==$claim['source_ref']||$reservation['source_operation_key']!=='mkt-paid:'.$claim['source_ref'].':activate'
                ||$envelope['currency']!==$claim['currency']||(int)$reservation['amount_minor']!==$cap
                ||(int)$envelope['reserved_minor']<$cap||$envelope['period_start']>$candidate['window_start']
                ||$envelope['period_end']<$candidate['window_end']){
                throw new DomainException('Stale or inconsistent Finance exposure.');
            }
            $this->connection->prepare('INSERT INTO cos_finance_paid_settlements
                (organization_id,reservation_id,envelope_id,action_id,actor_id,candidate_hash,currency,reserved_minor,committed_minor,released_minor,evidence_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$org,$reservationId,$id,$action,$actor,$candidateHash,$claim['currency'],$cap,$amount,$released,
                    json_encode($candidate,JSON_THROW_ON_ERROR)]);
            $r=$this->connection->prepare("UPDATE cos_finance_budget_reservations SET state='SETTLED',released_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=? AND state='RESERVED'");
            $r->execute([$org,$reservationId]);if($r->rowCount()!==1)throw new DomainException('Reservation transition lost.');
            $e=$this->connection->prepare('UPDATE cos_finance_budget_envelopes SET reserved_minor=reserved_minor-?,committed_minor=committed_minor+?,revision=revision+1 WHERE organization_id=? AND id=? AND revision=?');
            $e->execute([$cap,$amount,$org,$id,$expectedRevision]);if($e->rowCount()!==1)throw new DomainException('Budget transition lost.');
            $event=$this->connection->prepare('INSERT INTO cos_finance_budget_events
                (organization_id,id,envelope_id,reservation_id,event_type,amount_minor,actor_id,action_id,correlation_id,details_hash) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $event->execute([$org,'fine-'.bin2hex(random_bytes(12)),$id,$reservationId,'PAID_SPEND_COMMITTED',$amount,$actor,$action,$trace,$candidateHash]);
            $event->execute([$org,'fine-'.bin2hex(random_bytes(12)),$id,$reservationId,'PAID_RESERVE_RELEASED',$released,$actor,$action,$trace,$candidateHash]);
            $receipt=$this->readReceipt($org,$reservationId,true)??throw new DomainException('Settlement receipt missing.');
            $this->connection->commit();return [...$receipt,'fresh'=>true];
        }catch(Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }
    private function one(string $sql,array $params):?array
    {
        $q=$this->connection->prepare($sql);$q->execute($params);$row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }
}
