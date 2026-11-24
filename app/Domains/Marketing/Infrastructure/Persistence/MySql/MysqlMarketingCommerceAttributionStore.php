<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingCommerceAttributionStore;
use PDO;
final readonly class MysqlMarketingCommerceAttributionStore implements MarketingCommerceAttributionStore
{
    public function __construct(private PDO $connection){}
    public function record(string $org,string $campaign,array $e):array
    {
        if(!$this->connection->inTransaction())throw new DomainException('Commerce capture requires atomic receipt transaction.');
        $id='mkat-'.bin2hex(random_bytes(12));$key='order:'.$e['order_id'];
        $q=$this->connection->prepare("INSERT INTO marketing_attribution_evidence
            (organization_id,id,campaign_id,source_domain,source_event_id,policy_version,confidence,evidence_json,observed_at)
            VALUES (?,?,?,'sales',?,'commerce-utm-v1',1.0000,?,?) ON DUPLICATE KEY UPDATE id=id");
        $q->execute([$org,$id,$campaign,$key,json_encode($e,JSON_THROW_ON_ERROR),str_replace('T',' ',substr($e['occurred_at'],0,-1))]);
        $q=$this->connection->prepare("SELECT id,campaign_id,evidence_json FROM marketing_attribution_evidence
            WHERE organization_id=? AND source_domain='sales' AND source_event_id=? AND policy_version='commerce-utm-v1'");
        $q->execute([$org,$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
        $old=is_array($r)?json_decode($r['evidence_json'],true,16,JSON_THROW_ON_ERROR):null;
        if(!is_array($old)||$r['campaign_id']!==$campaign||$old['evidence_hash']!==$e['evidence_hash'])
            throw new DomainException('Native order already attributed; changed evidence requires reconciliation.');
        return ['id'=>$r['id'],'campaign_id'=>$campaign,...$old];
    }
    public function list(string $org,string $campaign,?string $plan,string $from,string $to):array
    {
        $sql="SELECT id,campaign_id,evidence_json FROM marketing_attribution_evidence WHERE organization_id=? AND campaign_id=?
            AND source_domain='sales' AND policy_version='commerce-utm-v1' AND observed_at>=? AND observed_at<?";
        $args=[$org,$campaign,$from.' 00:00:00',(new \DateTimeImmutable($to,new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
        if($plan!==null){$sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.plan_id'))=?";$args[]=$plan;}
        $q=$this->connection->prepare($sql.' ORDER BY observed_at,id LIMIT 501');$q->execute($args);
        return array_map(static fn(array $r)=>['id'=>$r['id'],'campaign_id'=>$r['campaign_id'],...json_decode($r['evidence_json'],true,16,JSON_THROW_ON_ERROR)],$q->fetchAll(PDO::FETCH_ASSOC));
    }
}
