<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;
use Domains\Marketing\Application\Contract\MarketingMediaAnalyticsStore;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Domain\MarketingAttributionPolicy;
use Domains\Marketing\Domain\MarketingMediaAnalyticsPolicy;
use DomainException;
use PDO;
use Throwable;

final readonly class MysqlMarketingMediaAnalyticsStore implements MarketingMediaAnalyticsStore
{
    public function __construct(private PDO $connection,private MarketingPaidReadRepository $snapshots){}
    public function byKey(string $org,string $key,string $requestHash):?array
    {
        $r=$this->one('SELECT * FROM marketing_media_analytics_runs WHERE organization_id=? AND idempotency_key=?',[$org,$key]);
        if($r===null)return null;
        if(!hash_equals($r['request_hash'],$requestHash))throw new DomainException('Media import key conflict.');
        return $this->project($r);
    }
    public function get(string $org,string $id):?array
    {
        $r=$this->one('SELECT * FROM marketing_media_analytics_runs WHERE organization_id=? AND id=?',[$org,$id]);
        return $r===null?null:$this->project($r);
    }
    public function latest(string $org,string $account,string $grain,string $from,string $to):?array
    {
        $r=$this->one('SELECT * FROM marketing_media_analytics_runs WHERE organization_id=? AND account_id=? AND grain=? AND window_start=? AND window_end=?
            ORDER BY created_at DESC,id DESC LIMIT 1',[$org,$account,$grain,$from,$to]);
        return $r===null?null:$this->project($r);
    }
    public function commit(string $org,string $actor,string $key,string $requestHash,array $account,string $grain,string $from,string $to,array $rows):array
    {
        if($this->connection->inTransaction())throw new DomainException('Nested media analytics import denied.');
        $providerRows=$rows;
        $rows=MarketingMediaAnalyticsPolicy::sanitize($account,$grain,$from,$to,$providerRows);
        $this->connection->beginTransaction();
        try{
            // Same account lock as native Marketing snapshot writer. Account changes during remote fetch fail closed.
            $locked=$this->one('SELECT id,revision,currency,timezone,connection_status,ad_account_id FROM marketing_paid_accounts WHERE organization_id=? AND id=? FOR UPDATE',[$org,$account['id']]);
            if($locked===null||(int)$locked['revision']!==$account['revision']||$locked['currency']!==$account['currency']||$locked['timezone']!==$account['timezone']
                ||$locked['ad_account_id']!==$account['ad_account_id']||$locked['connection_status']!=='READ_ONLY')throw new DomainException('Media account changed during import.');
            $old=$this->one('SELECT * FROM marketing_media_analytics_runs WHERE organization_id=? AND idempotency_key=? FOR UPDATE',[$org,$key]);
            if($old!==null){
                if(!hash_equals($requestHash,$old['request_hash']))throw new DomainException('Media import key conflict.');
                $this->connection->commit();return $this->project($old);
            }
            $count=$this->snapshots->importInsights($org,$account['id'],$grain,$from,$to,$providerRows);
            if($count!==count($rows))throw new DomainException('Incomplete native media import.');
            $field=['account'=>'account_id','campaign'=>'campaign_id','adset'=>'adset_id','ad'=>'ad_id'][$grain];
            $evidence=[];$attribution=hash('sha256','meta-default-adset-attribution-v1');
            foreach($rows as $raw){
                $r=$this->one('SELECT id,source_hash,attribution_hash,source_status,spend_decimal,metrics_json,revision,fetched_at,currency,timezone
                    FROM marketing_paid_insight_snapshots WHERE organization_id=? AND account_id=? AND grain=? AND external_id=? AND window_start=? AND window_end=? AND attribution_hash=?',
                    [$org,$account['id'],$grain,$raw[$field],$from,$to,$attribution]);
                if($r===null||$r['currency']!==$account['currency']||$r['timezone']!==$account['timezone'])throw new DomainException('Native media readback differs.');
                $r['metrics']=json_decode($r['metrics_json'],true,32,JSON_THROW_ON_ERROR);$r['revision']=(int)$r['revision'];
                $evidence[]=['external_id'=>$raw[$field],...MarketingAttributionPolicy::media($r),'reach'=>MarketingAttributionPolicy::count($r['metrics']['reach']??null),
                    'source_hash'=>$r['source_hash'],'attribution_hash'=>$r['attribution_hash'],'provider_actions'=>$raw['actions']??[],'provider_action_values'=>$raw['action_values']??[]];
            }
            $id='mkar-'.bin2hex(random_bytes(12));$facts=['currency'=>$account['currency'],'timezone'=>$account['timezone'],'attribution_hash'=>$attribution,'rows'=>$evidence];
            $q=$this->connection->prepare('INSERT INTO marketing_media_analytics_runs
                (organization_id,id,account_id,account_revision,grain,window_start,window_end,idempotency_key,request_hash,actor_id,evidence_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$org,$id,$account['id'],$account['revision'],$grain,$from,$to,$key,$requestHash,$actor,json_encode($facts,JSON_THROW_ON_ERROR)]);
            $receipt=$this->get($org,$id)??throw new DomainException('Media analytics receipt missing.');
            $this->connection->commit();return $receipt;
        }catch(Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw $e;}
    }
    private function project(array $r):array
    {
        $e=json_decode($r['evidence_json'],true,32,JSON_THROW_ON_ERROR);
        return ['id'=>$r['id'],'account_id'=>$r['account_id'],'account_revision'=>(int)$r['account_revision'],'grain'=>$r['grain'],
            'window_start'=>$r['window_start'],'window_end'=>$r['window_end'],'created_at'=>$r['created_at'],...$e,
            'state'=>'PROVIDER_REPORTED','recognized_revenue_confirmed'=>false];
    }
    private function one(string $sql,array $parameters):?array
    {
        $q=$this->connection->prepare($sql);$q->execute($parameters);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
}
