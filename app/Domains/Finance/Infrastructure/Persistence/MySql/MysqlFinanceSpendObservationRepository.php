<?php
declare(strict_types=1);

namespace Domains\Finance\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Finance\Application\Contract\FinanceSpendObservationRepositoryInterface;
use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use PDO;
use PDOException;
use Throwable;

/**
 * Finance-owned copy of source-reported evidence, not a general spend aggregate.
 * An exact window, campaign grain, timezone, currency and attribution are mandatory.
 * Never updates Budget commitments, Expense, Invoice, Payment or FX.
 */
final readonly class MysqlFinanceSpendObservationRepository implements FinanceSpendObservationRepositoryInterface
{
    public function __construct(private PDO $connection,private FinanceSpendObservationPolicy $policy){}

    public function record(string $org,array $raw,string $correlationId):array
    {
        $e=$this->policy->validate($raw);
        if($org===''||strlen($org)>190||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$correlationId)){
            throw new DomainException('Finance evidence requires tenant and stable correlation.');
        }
        if($this->connection->inTransaction())throw new DomainException('Nested spend-evidence import denied.');
        try {
            $this->connection->beginTransaction();
            $existing=$this->lookupLocked($org,$e);
            if($existing===null){
                $id='fins-'.bin2hex(random_bytes(12));
                $this->connection->prepare('INSERT INTO cos_finance_spend_observations
                    (organization_id,id,provider,source_domain,ad_account_ref,source_snapshot_ref,
                    grain,external_id,window_start,window_end,timezone,currency,attribution_hash,
                    source_hash,source_revision,source_status,reported_spend_decimal,evidence_verification)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                    $org,$id,$e['provider'],$e['source_domain'],$e['ad_account_ref'],$e['source_snapshot_ref'],
                    $e['grain'],$e['external_id'],$e['window_start'],$e['window_end'],$e['timezone'],
                    $e['currency'],$e['attribution_hash'],$e['source_hash'],$e['source_revision'],
                    $e['source_status'],$e['reported_spend_decimal'],'UNVERIFIED_SOURCE'
                ]);
                $this->event($org,$id,'SOURCE_OBSERVED',$e,$correlationId);
            } else {
                $id=(string)$existing['id'];
                if($existing['source_snapshot_ref']!==$e['source_snapshot_ref']
                    ||$existing['currency']!==$e['currency']
                    ||$existing['timezone']!==$e['timezone']){
                    throw new DomainException('Provider scope changed; Finance evidence cannot be silently reassigned.');
                }
                $oldRevision=(int)$existing['source_revision'];
                $oldValue=self::canonicalDecimal((string)$existing['reported_spend_decimal']);
                $newValue=self::canonicalDecimal($e['reported_spend_decimal']);
                if($oldRevision===$e['source_revision']){
                    if($existing['source_hash']!==$e['source_hash']
                        ||$existing['source_status']!==$e['source_status']
                        ||$oldValue!==$newValue){
                        throw new DomainException('Provider evidence version collision.');
                    }
                }elseif($oldRevision>$e['source_revision']){
                    throw new DomainException('Stale provider evidence revision; Finance will not roll back its observation.');
                }else {
                    $this->connection->prepare('UPDATE cos_finance_spend_observations
                        SET source_revision=?,source_hash=?,source_status=?,reported_spend_decimal=?,
                        last_observed_at=UTC_TIMESTAMP(6)
                        WHERE organization_id=? AND id=?')->execute([
                            $e['source_revision'],$e['source_hash'],$e['source_status'],$e['reported_spend_decimal'],
                            $org,$id
                        ]);
                    $this->event($org,$id,'SOURCE_REVISED',$e,$correlationId);
                }
            }
            $result=$this->read($org,$id);
            $this->connection->commit();
            return $result;
        }catch(Throwable $failure){
            if($this->connection->inTransaction())$this->connection->rollBack();
            if($failure instanceof PDOException&&$failure->getCode()==='23000'){
                // Unique-scope concurrent insert. Do not replay blind; recover ONLY exact matching receipt.
                $q=$this->connection->prepare('SELECT id,source_snapshot_ref,source_revision,source_hash,source_status,
                    reported_spend_decimal,currency,timezone FROM cos_finance_spend_observations
                    WHERE organization_id=? AND provider=? AND ad_account_ref=? AND grain=? AND external_id=?
                    AND window_start=? AND window_end=? AND attribution_hash=?');
                $q->execute([$org,$e['provider'],$e['ad_account_ref'],$e['grain'],$e['external_id'],
                    $e['window_start'],$e['window_end'],$e['attribution_hash']]);
                $found=$q->fetch(PDO::FETCH_ASSOC);
                if(is_array($found)
                    &&$found['source_snapshot_ref']===$e['source_snapshot_ref']
                    &&(int)$found['source_revision']===$e['source_revision']
                    &&$found['source_hash']===$e['source_hash']
                    &&$found['source_status']===$e['source_status']
                    &&self::canonicalDecimal((string)$found['reported_spend_decimal'])===self::canonicalDecimal($e['reported_spend_decimal'])
                    &&$found['currency']===$e['currency']&&$found['timezone']===$e['timezone']){
                    return $this->read($org,(string)$found['id']);
                }
                throw new DomainException('Concurrent Finance spend evidence conflict; reconcile source before retry.');
            }
            throw $failure;
        }
    }

    public function reconciliationStatus(string $org,array $rawScope):array
    {
        $s=$this->policy->scope($rawScope['ad_account_ref'],$rawScope['window_start'],$rawScope['window_end'],
            $rawScope['currency'],$rawScope['timezone'],$rawScope['attribution_hash']);
        // Sum only the exact same campaign-level period and attribution. No mixed grain/date/currency/FX.
        $q=$this->connection->prepare("SELECT COUNT(*) AS observation_count,
            COALESCE(SUM(reported_spend_decimal),0) AS source_reported_spend_decimal,
            SUM(CASE WHEN evidence_verification='UNVERIFIED_SOURCE' THEN 1 ELSE 0 END) AS unverified_count
            FROM cos_finance_spend_observations
            WHERE organization_id=? AND provider='meta' AND grain='campaign'
            AND ad_account_ref=? AND window_start=? AND window_end=?
            AND currency=? AND timezone=? AND attribution_hash=?");
        $q->execute([$org,$s['ad_account_ref'],$s['window_start'],$s['window_end'],
            $s['currency'],$s['timezone'],$s['attribution_hash']]);
        $row=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $count=(int)($row['observation_count']??0);
        return [
            'status'=>$count===0?'NO_OBSERVATIONS':'UNRECONCILED',
            'ad_account_ref'=>$s['ad_account_ref'],
            'provider'=>'meta','grain'=>'campaign',
            'window_start'=>$s['window_start'],'window_end'=>$s['window_end'],
            'timezone'=>$s['timezone'],'currency'=>$s['currency'],
            'attribution_hash'=>$s['attribution_hash'],
            'source_reported_spend_decimal'=>self::canonicalDecimal((string)($row['source_reported_spend_decimal']??'0')),
            'source_count'=>$count,'unverified_count'=>(int)($row['unverified_count']??0),
            'provider_observation_only'=>true,
            'finance_expense_confirmed'=>false,
            'invoice_reconciled'=>false,
            'budget_exposure_coverage_confirmed'=>false
        ];
    }

    /** @param array<string,mixed> $e @return array<string,mixed>|null */
    private function lookupLocked(string $org,array $e):?array
    {
        $q=$this->connection->prepare('SELECT * FROM cos_finance_spend_observations WHERE organization_id=?
            AND provider=? AND ad_account_ref=? AND grain=? AND external_id=? AND window_start=?
            AND window_end=? AND attribution_hash=? FOR UPDATE');
        $q->execute([$org,$e['provider'],$e['ad_account_ref'],$e['grain'],$e['external_id'],
            $e['window_start'],$e['window_end'],$e['attribution_hash']]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /** @return array<string,mixed> */
    private function read(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT id,provider,source_domain,ad_account_ref,source_snapshot_ref,
            grain,external_id,window_start,window_end,timezone,currency,attribution_hash,
            source_hash,source_revision,source_status,reported_spend_decimal,evidence_verification,
            first_observed_at,last_observed_at
            FROM cos_finance_spend_observations WHERE organization_id=? AND id=?');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Finance spend evidence receipt unavailable.');
        $row['source_revision']=(int)$row['source_revision'];
        $row['reported_spend_decimal']=self::canonicalDecimal((string)$row['reported_spend_decimal']);
        $row['finance_expense_confirmed']=false;
        return $row;
    }

    /** @param array<string,mixed> $e */
    private function event(string $org,string $id,string $type,array $e,string $correlation):void
    {
        $this->connection->prepare('INSERT INTO cos_finance_spend_evidence_events
            (organization_id,id,observation_id,event_type,source_revision,source_hash,reported_spend_decimal,correlation_id)
            VALUES (?,?,?,?,?,?,?,?)')->execute([
                $org,'finse-'.bin2hex(random_bytes(12)),$id,$type,
                $e['source_revision'],$e['source_hash'],$e['reported_spend_decimal'],$correlation
            ]);
    }

    private static function canonicalDecimal(string $value):string
    {
        // MySQL DECIMAL returns fixed scale; keep string/decimal precision (no PHP float).
        if(!preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,6}))?$/D',$value,$m)){
            throw new DomainException('Invalid Finance reported decimal representation.');
        }
        $fraction=rtrim((string)($m[2]??''),'0');
        return $m[1].($fraction!==''?'.'.$fraction:'');
    }
}

