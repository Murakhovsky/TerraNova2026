<?php
declare(strict_types=1);
namespace Domains\Finance\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Finance\Application\Contract\FinanceMarketingSpendActionIntentRepositoryInterface;
use PDO;
use Throwable;

/**
 * Separate Finance-owned idempotency ledger. A CLAIMED intent is safe to retry
 * only for the exact same action/account/source revision/hash. Finance's source
 * observation writer remains independently revision-idempotent.
 */
final readonly class MysqlFinanceMarketingSpendActionIntentRepository implements FinanceMarketingSpendActionIntentRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function claim(string $org,string $key,string $account,string $snapshot,int $revision,string $sourceHash):array
    {
        self::check($org,$key,$account,$snapshot,$revision,$sourceHash);
        $db=$this->connection;
        if($db->inTransaction())throw new DomainException('Nested Finance evidence intent transaction prohibited.');
        $db->beginTransaction();
        try{
            $db->prepare('INSERT INTO cos_finance_spend_import_action_intents
                (organization_id,idempotency_key,ad_account_ref,source_snapshot_ref,expected_revision,expected_source_hash)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE idempotency_key=idempotency_key')
                ->execute([$org,$key,$account,$snapshot,$revision,$sourceHash]);
            $q=$db->prepare('SELECT ad_account_ref,source_snapshot_ref,expected_revision,expected_source_hash,state,finance_observation_id
                FROM cos_finance_spend_import_action_intents
                WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$org,$key]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)
                ||$row['ad_account_ref']!==$account
                ||$row['source_snapshot_ref']!==$snapshot
                ||(int)$row['expected_revision']!==$revision
                ||$row['expected_source_hash']!==$sourceHash){
                throw new DomainException('Finance Action idempotency key reused for a different provider source or revision.');
            }
            $result=['state'=>(string)$row['state'],
                'finance_observation_id'=>$row['finance_observation_id']===null?null:(string)$row['finance_observation_id']];
            $db->commit();
            return $result;
        }catch(Throwable $error){
            if($db->inTransaction())$db->rollBack();
            throw $error;
        }
    }

    public function complete(string $org,string $key,string $observationId,int $revision,string $sourceHash):void
    {
        if(!preg_match('/^fins-[a-f0-9]{24}$/D',$observationId)){
            throw new DomainException('Finance Action requires a persisted observation reference.');
        }
        $db=$this->connection;
        if($db->inTransaction())throw new DomainException('Nested Finance source Action completion prohibited.');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT expected_revision,expected_source_hash,state,finance_observation_id
                FROM cos_finance_spend_import_action_intents
                WHERE organization_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$org,$key]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)
                ||(int)$row['expected_revision']!==$revision
                ||$row['expected_source_hash']!==$sourceHash
                ||($row['state']==='SUCCEEDED'&&$row['finance_observation_id']!==$observationId)){
                throw new DomainException('Finance source Action completion conflicts with immutable approved intent.');
            }
            if($row['state']!=='SUCCEEDED'){
                $db->prepare("UPDATE cos_finance_spend_import_action_intents
                    SET state='SUCCEEDED',finance_observation_id=?,completed_at=UTC_TIMESTAMP(6)
                    WHERE organization_id=? AND idempotency_key=? AND state='CLAIMED'")
                    ->execute([$observationId,$org,$key]);
            }
            $db->commit();
        }catch(Throwable $failure){
            if($db->inTransaction())$db->rollBack();
            throw $failure;
        }
    }

    private static function check(string $org,string $key,string $account,string $snapshot,int $revision,string $sourceHash):void
    {
        if($org===''||strlen($org)>190
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D',$key)
            ||!preg_match('/^mads-[a-f0-9]{24}$/D',$account)
            ||!preg_match('/^mis-[a-f0-9]{24}$/D',$snapshot)
            ||$revision<1||$revision>2000000000
            ||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash)){
            throw new DomainException('Invalid Finance Action evidence scope or stable idempotency key.');
        }
    }
}
