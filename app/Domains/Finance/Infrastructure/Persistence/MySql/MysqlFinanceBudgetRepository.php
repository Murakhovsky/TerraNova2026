<?php
declare(strict_types=1);

namespace Domains\Finance\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Finance\Application\Contract\FinanceBudgetRepositoryInterface;
use Domains\Finance\Application\Contract\FinancePaidExposureClaimStore;
use Domains\Finance\Domain\BudgetControlPolicy;
use PDO;
use PDOException;
use Throwable;

/**
 * Finance is the only writer for budget envelopes/reservations.
 * Every financial mutation: single tenant, explicit row lock, immutable event,
 * stable idempotency receipt and one transaction.
 */
final readonly class MysqlFinanceBudgetRepository implements FinanceBudgetRepositoryInterface,FinancePaidExposureClaimStore
{
    public function __construct(private PDO $connection){}

    public function createDraft(string $org,string $actor,array $draft,string $key):array
    {
        $fingerprint=self::digest($draft);
        try {
            return $this->transaction(function()use($org,$actor,$draft,$key,$fingerprint):array {
                $receipt=$this->receipt($org,'draft',$key,$fingerprint);
                if($receipt!==null)return $this->requireEnvelope($org,$receipt);
                $id='finb-'.bin2hex(random_bytes(12));
                $this->connection->prepare("INSERT INTO cos_finance_budget_envelopes
                    (organization_id,id,owner_id,source_domain,source_ref,name,currency,period_start,period_end,limit_minor,state)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'DRAFT')")->execute([
                    $org,$id,$actor,$draft['source_domain'],$draft['source_ref'],$draft['name'],$draft['currency'],
                    $draft['period_start'],$draft['period_end'],$draft['limit_minor']
                ]);
                $this->saveReceipt($org,'draft',$key,$fingerprint,$id);
                $this->event($org,$id,null,'DRAFT_CREATED',0,$actor,'human_draft','human_draft',$fingerprint);
                return $this->requireEnvelope($org,$id);
            });
        } catch(PDOException $e) {
            if($e->getCode()==='23000'){
                $ref=$this->receipt($org,'draft',$key,$fingerprint,false);
                if($ref!==null)return $this->requireEnvelope($org,$ref);
            }
            throw $e;
        }
    }

    public function envelope(string $org,string $id):?array
    {
        $q=$this->connection->prepare('SELECT id,owner_id,source_domain,source_ref,name,currency,period_start,period_end,limit_minor,reserved_minor,committed_minor,state,revision,created_at,updated_at FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=?');
        $q->execute([$org,$id]);$record=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($record)?self::projection($record):null;
    }

    public function reservations(string $org,string $id):array
    {
        // Do not treat an unknown/cross-tenant envelope as an empty collection.
        if($this->envelope($org,$id)===null)throw new DomainException('Finance budget envelope unavailable.');
        $stmt=$this->connection->prepare('SELECT id,envelope_id,source_domain,source_operation_key,amount_minor,state,actor_id,action_id,reason,created_at,updated_at,released_at FROM cos_finance_budget_reservations WHERE organization_id=? AND envelope_id=? ORDER BY created_at DESC,id DESC LIMIT 500');
        $stmt->execute([$org,$id]);
        return array_map(self::reservationProjection(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function activate(string $org,string $id,string $actor,string $action,string $trace,int $expectedRevision,string $key):array
    {
        $hash=self::digest(['id'=>$id,'revision'=>$expectedRevision]);
        return $this->transaction(function()use($org,$id,$actor,$action,$trace,$expectedRevision,$key,$hash):array {
            $row=$this->lockedEnvelope($org,$id);
            $receipt=$this->receipt($org,'activate',$key,$hash);
            if($receipt!==null)return $this->requireEnvelope($org,$receipt);
            $this->expectedRevision($row,$expectedRevision);
            if($row['state']!=='DRAFT')throw new DomainException('Only a draft budget can be activated.');
            if($row['period_end'] < self::utcToday())throw new DomainException('Cannot activate an expired budget.');
            $this->connection->prepare("UPDATE cos_finance_budget_envelopes SET state='ACTIVE',revision=revision+1 WHERE organization_id=? AND id=?")->execute([$org,$id]);
            $this->saveReceipt($org,'activate',$key,$hash,$id);
            $this->event($org,$id,null,'ENVELOPE_ACTIVATED',0,$actor,$action,$trace,$hash);
            return $this->requireEnvelope($org,$id);
        });
    }

    public function reserve(string $org,string $id,string $actor,string $action,string $trace,array $input,string $key):array
    {
        $hash=self::digest(['envelope_id'=>$id,...$input]);
        return $this->transaction(function()use($org,$id,$actor,$action,$trace,$input,$key,$hash):array {
            $row=$this->lockedEnvelope($org,$id);
            $receipt=$this->receipt($org,'reserve',$key,$hash);
            if($receipt!==null)return $this->requireReservation($org,$receipt);
            $this->expectedRevision($row,$input['expected_revision']);
            if($row['state']!=='ACTIVE')throw new DomainException('Budget is not ACTIVE.');
            $today=self::utcToday();
            if($today<$row['period_start']||$today>$row['period_end']){
                throw new DomainException('Reservation outside Finance UTC budget period.');
            }
            if($input['source_domain']!==$row['source_domain'])throw new DomainException('Cross-purpose budget reservation denied.');
            // A provider operation cannot be funded twice, even with different Action IDs.
            $check=$this->connection->prepare('SELECT id FROM cos_finance_budget_reservations WHERE organization_id=? AND source_domain=? AND source_operation_key=? FOR UPDATE');
            $check->execute([$org,$input['source_domain'],$input['source_operation_key']]);
            if($check->fetchColumn()!==false)throw new DomainException('Provider operation already has a Finance reservation.');
            $available=(int)$row['limit_minor']-(int)$row['committed_minor']-(int)$row['reserved_minor'];
            if($available<$input['amount_minor'])throw new DomainException('Budget available amount is insufficient.');
            $reservationId='finr-'.bin2hex(random_bytes(12));
            $this->connection->prepare("INSERT INTO cos_finance_budget_reservations
                (organization_id,id,envelope_id,source_domain,source_operation_key,amount_minor,state,actor_id,action_id,reason)
                VALUES (?,?,?,?,?,?,'RESERVED',?,?,?)")->execute([
                $org,$reservationId,$id,$input['source_domain'],$input['source_operation_key'],$input['amount_minor'],$actor,$action,$input['reason']
            ]);
            $this->connection->prepare('UPDATE cos_finance_budget_envelopes SET reserved_minor=reserved_minor+?,revision=revision+1 WHERE organization_id=? AND id=?')
                ->execute([$input['amount_minor'],$org,$id]);
            $this->saveReceipt($org,'reserve',$key,$hash,$reservationId);
            $this->event($org,$id,$reservationId,'FUNDS_RESERVED',$input['amount_minor'],$actor,$action,$trace,$hash);
            return $this->requireReservation($org,$reservationId);
        });
    }

    public function release(string $org,string $reservationId,string $actor,string $action,string $trace,string $reason,string $key):array
    {
        $hash=self::digest(['reservation_id'=>$reservationId,'reason'=>$reason]);
        return $this->transaction(function()use($org,$reservationId,$actor,$action,$trace,$reason,$key,$hash):array {
            // Resolve the parent, then acquire locks consistently (envelope first, reservation second).
            $lookup=$this->connection->prepare('SELECT envelope_id FROM cos_finance_budget_reservations WHERE organization_id=? AND id=?');
            $lookup->execute([$org,$reservationId]);
            $envelopeId=$lookup->fetchColumn();
            if(!is_string($envelopeId)||$envelopeId==='')throw new DomainException('Finance reservation unavailable.');
            $this->lockedEnvelope($org,$envelopeId);
            $row=$this->lockedReservation($org,$reservationId);
            $receipt=$this->receipt($org,'release',$key,$hash);
            if($receipt!==null)return $this->requireReservation($org,$receipt);
            if($row['state']!=='RESERVED')throw new DomainException('Only an outstanding reservation can be released.');
            if($this->readExposure($org,$reservationId,true)!==null)throw new DomainException('Claimed provider exposure requires independent reconciliation; release is blocked.');
            $this->connection->prepare("UPDATE cos_finance_budget_reservations SET state='RELEASED',released_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND id=?")
                ->execute([$org,$reservationId]);
            $this->connection->prepare('UPDATE cos_finance_budget_envelopes SET reserved_minor=reserved_minor-?,revision=revision+1 WHERE organization_id=? AND id=?')
                ->execute([(int)$row['amount_minor'],$org,$envelopeId]);
            $this->saveReceipt($org,'release',$key,$hash,$reservationId);
            $this->event($org,$envelopeId,$reservationId,'FUNDS_RELEASED',(int)$row['amount_minor'],$actor,$action,$trace,self::digest(['hash'=>$hash,'reason'=>$reason]));
            return $this->requireReservation($org,$reservationId);
        });
    }

    public function freeze(string $org,string $id,string $actor,string $action,string $trace,string $reason,int $expectedRevision,string $key):array
    {
        $hash=self::digest(['id'=>$id,'reason'=>$reason,'revision'=>$expectedRevision]);
        return $this->transaction(function()use($org,$id,$actor,$action,$trace,$reason,$expectedRevision,$key,$hash):array {
            $row=$this->lockedEnvelope($org,$id);
            $receipt=$this->receipt($org,'freeze',$key,$hash);
            if($receipt!==null)return $this->requireEnvelope($org,$receipt);
            $this->expectedRevision($row,$expectedRevision);
            if($row['state']!=='ACTIVE')throw new DomainException('Only ACTIVE budget can be frozen.');
            $this->connection->prepare("UPDATE cos_finance_budget_envelopes SET state='FROZEN',revision=revision+1 WHERE organization_id=? AND id=?")->execute([$org,$id]);
            $this->saveReceipt($org,'freeze',$key,$hash,$id);
            $this->event($org,$id,null,'ENVELOPE_FROZEN',0,$actor,$action,$trace,self::digest(['hash'=>$hash,'reason'=>$reason]));
            return $this->requireEnvelope($org,$id);
        });
    }

    public function exposure(string $org,string $reservationId):?array{return $this->readExposure($org,$reservationId,false);}

    private function readExposure(string $org,string $reservationId,bool $locked):?array
    {
        $q=$this->connection->prepare('SELECT reservation_id,envelope_id,source_ref,action_id,actor_id,request_hash,plan_hash,amount_minor,currency,created_at
            FROM cos_finance_paid_exposure_claims WHERE organization_id=? AND reservation_id=?'.($locked?' FOR UPDATE':''));
        $q->execute([$org,$reservationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $row['amount_minor']=(int)$row['amount_minor'];
        return $row;
    }

    public function claimExposure(string $org,string $envelopeId,string $reservationId,string $actor,string $action,
        string $trace,array $input):array
    {
        $hash=self::digest(['envelope_id'=>$envelopeId,'reservation_id'=>$reservationId,'actor'=>$actor,'action'=>$action,...$input]);
        return $this->transaction(function()use($org,$envelopeId,$reservationId,$actor,$action,$trace,$input,$hash):array{
            // Same lock order as release. No provider call in this transaction.
            $e=$this->lockedEnvelope($org,$envelopeId);
            $r=$this->lockedReservation($org,$reservationId);
            if($r['envelope_id']!==$envelopeId)throw new DomainException('Exposure envelope mismatch.');
            $existing=$this->readExposure($org,$reservationId,true);
            if($existing!==null){
                if(!hash_equals($existing['request_hash'],$hash))throw new DomainException('Reservation already claimed by another exposure.');
                // Replay cannot authorize a second external mutation.
                return [...$existing,'fresh'=>false];
            }
            $this->expectedRevision($e,$input['expected_revision']);
            $today=self::utcToday();
            if($e['state']!=='ACTIVE'||$r['state']!=='RESERVED'||$e['source_domain']!=='marketing'||$r['source_domain']!=='marketing'
                ||$e['source_ref']!==$input['source_ref']||$r['source_operation_key']!==$input['source_operation_key']
                ||$e['currency']!==$input['currency']||(int)$r['amount_minor']!==$input['amount_minor']
                ||(int)$e['reserved_minor']<$input['amount_minor']||$e['period_start']>$today||$e['period_end']<$today
                ||$e['period_start']>$input['period_start']||$e['period_end']<$input['period_end']
                ||$today>$input['period_end']){
                throw new DomainException('Finance exposure no longer backed by exact active reservation.');
            }
            $this->connection->prepare('INSERT INTO cos_finance_paid_exposure_claims
                (organization_id,reservation_id,envelope_id,source_ref,action_id,actor_id,request_hash,plan_hash,amount_minor,currency)
                VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$org,$reservationId,$envelopeId,$input['source_ref'],$action,$actor,
                    $hash,$input['plan_hash'],$input['amount_minor'],$input['currency']]);
            $this->connection->prepare('UPDATE cos_finance_budget_envelopes SET revision=revision+1 WHERE organization_id=? AND id=?')
                ->execute([$org,$envelopeId]);
            $this->event($org,$envelopeId,$reservationId,'PAID_EXPOSURE_CLAIMED',$input['amount_minor'],$actor,$action,$trace,$hash);
            $claim=$this->exposure($org,$reservationId)??throw new DomainException('Exposure receipt missing.');
            return [...$claim,'fresh'=>true];
        });
    }

    private function transaction(callable $operation):mixed
    {
        if($this->connection->inTransaction())throw new DomainException('Nested Finance budget transactions forbidden.');
        $this->connection->beginTransaction();
        try {$result=$operation();$this->connection->commit();return $result;}
        catch(Throwable $failure){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $failure;
        }
    }

    private function lockedEnvelope(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT * FROM cos_finance_budget_envelopes WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Finance budget envelope unavailable.');
        return $row;
    }
    private function lockedReservation(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT * FROM cos_finance_budget_reservations WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Finance reservation unavailable.');
        return $row;
    }
    private function requireEnvelope(string $org,string $id):array
    {
        return $this->envelope($org,$id)??throw new DomainException('Finance budget state missing after commit.');
    }
    private function requireReservation(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT id,envelope_id,source_domain,source_operation_key,amount_minor,state,actor_id,action_id,reason,created_at,updated_at,released_at FROM cos_finance_budget_reservations WHERE organization_id=? AND id=?');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::reservationProjection($row):throw new DomainException('Finance reservation receipt missing.');
    }
    /** Returns a verified stable reference, or null; caller holds Finance row locks for state transitions. */
    private function receipt(string $org,string $operation,string $key,string $hash,bool $locked=true):?string
    {
        $q=$this->connection->prepare('SELECT request_hash,reference_id FROM cos_finance_budget_intents WHERE organization_id=? AND operation=? AND idempotency_key=?'.($locked?' FOR UPDATE':''));
        $q->execute([$org,$operation,$key]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        if(!hash_equals((string)$row['request_hash'],$hash))throw new DomainException('Finance idempotency collision with conflicting payload.');
        return (string)$row['reference_id'];
    }
    private function saveReceipt(string $org,string $operation,string $key,string $hash,string $reference):void
    {
        $this->connection->prepare('INSERT INTO cos_finance_budget_intents (organization_id,operation,idempotency_key,request_hash,reference_id) VALUES (?,?,?,?,?)')
            ->execute([$org,$operation,$key,$hash,$reference]);
    }
    private function event(string $org,string $envelopeId,?string $reservationId,string $type,int $amount,
        string $actor,string $action,string $correlation,string $digest):void
    {
        $this->connection->prepare('INSERT INTO cos_finance_budget_events (organization_id,id,envelope_id,reservation_id,event_type,amount_minor,actor_id,action_id,correlation_id,details_hash) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$org,'fine-'.bin2hex(random_bytes(12)),$envelopeId,$reservationId,$type,$amount,$actor,$action,$correlation,$digest]);
    }
    private function expectedRevision(array $row,int $expected):void
    {
        if((int)$row['revision']!==$expected)throw new DomainException('Stale Finance budget revision; re-read and re-approve.');
    }
    private static function utcToday():string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');
    }
    /** @param array<string,mixed> $value */
    private static function digest(array $value):string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function projection(array $row):array
    {
        foreach(['limit_minor','reserved_minor','committed_minor','revision'] as $k)$row[$k]=(int)$row[$k];
        $row['available_minor']=$row['limit_minor']-$row['reserved_minor']-$row['committed_minor'];
        // Never expose a tenant-org column from arbitrary query input.
        unset($row['organization_id']);
        return $row;
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function reservationProjection(array $row):array
    {
        $row['amount_minor']=(int)$row['amount_minor'];
        return $row;
    }
}

