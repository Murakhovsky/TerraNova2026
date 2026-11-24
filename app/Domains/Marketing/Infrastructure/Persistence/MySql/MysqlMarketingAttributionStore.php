<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Persistence\MySql;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingAttributionStore;
use PDO;
final readonly class MysqlMarketingAttributionStore implements MarketingAttributionStore
{
    public function __construct(private PDO $connection){}
    public function record(string $org,string $campaign,array $evidence):array
    {
        $id='mkat-'.bin2hex(random_bytes(12));
        $q=$this->connection->prepare("INSERT INTO marketing_attribution_evidence
            (organization_id,id,campaign_id,source_domain,source_event_id,policy_version,confidence,evidence_json,observed_at)
            VALUES (?,?,?,'sales',?,'utm-exact-v1',1.0000,?,?) ON DUPLICATE KEY UPDATE id=id");
        $q->execute([$org,$id,$campaign,$evidence['lead_id'],json_encode($evidence,JSON_THROW_ON_ERROR),$evidence['created_at']]);
        $q=$this->connection->prepare("SELECT id,campaign_id,evidence_json FROM marketing_attribution_evidence
            WHERE organization_id=? AND source_domain='sales' AND source_event_id=? AND policy_version='utm-exact-v1'");
        $q->execute([$org,$evidence['lead_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
        $old=is_array($r)?json_decode($r['evidence_json'],true,16,JSON_THROW_ON_ERROR):null;
        if(!is_array($old)||$r['campaign_id']!==$campaign||$old['evidence_hash']!==$evidence['evidence_hash'])
            throw new DomainException('Attribution already recorded with different source evidence; manual reconciliation required.');
        return ['id'=>$r['id'],'campaign_id'=>$campaign,...$old];
    }
    public function list(string $org,string $campaign,string $from,string $to,int $limit,int $offset):array
    {
        if($limit<1||$limit>501||$offset<0||$offset>100000)throw new DomainException('Attribution page outside bounds.');
        $q=$this->connection->prepare("SELECT id,campaign_id,evidence_json FROM marketing_attribution_evidence
            WHERE organization_id=? AND campaign_id=? AND source_domain='sales' AND policy_version='utm-exact-v1'
            AND observed_at>=? AND observed_at<? ORDER BY observed_at,id LIMIT ".$limit." OFFSET ".$offset);
        $q->execute([$org,$campaign,$from.' 00:00:00',(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00']);
        return array_map(static fn(array $r)=>['id'=>$r['id'],'campaign_id'=>$r['campaign_id'],...json_decode($r['evidence_json'],true,16,JSON_THROW_ON_ERROR)],$q->fetchAll(PDO::FETCH_ASSOC));
    }
}
