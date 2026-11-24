<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOfferRepositoryInterface;
use Domains\Sales\Application\Contract\SalesCommercialEvidencePort;
use PDO;
use DomainException;

final readonly class MysqlSalesOfferRepository implements SalesOfferRepositoryInterface,SalesCommercialEvidencePort
{
    public function __construct(private PDO $connection){}
    private const SELECT='SELECT o.*,v.title,v.currency,v.total,v.valid_until,v.lines_json,v.direction,v.terms_hash FROM sales_offers o
        INNER JOIN sales_offer_revisions v ON v.organization_id=o.organization_id AND v.offer_id=o.offer_id AND v.terms_version=o.terms_version';
    public function get(string $org,string $id):?array
    {
        return $this->one(self::SELECT.' WHERE o.organization_id=? AND o.offer_id=?',[$org,$id]);
    }
    public function forDeal(string $org,int $deal,int $limit=50):array
    {
        return $this->all(self::SELECT.' WHERE o.organization_id=? AND o.deal_id=? ORDER BY o.created_at DESC,o.offer_id LIMIT '.max(1,min(100,$limit)),[$org,$deal]);
    }
    public function acceptedForDeal(string $org,int $deal):?array
    {
        return $this->one(self::SELECT.' WHERE o.organization_id=? AND o.deal_id=? AND o.status="accepted" LIMIT 1',[$org,$deal]);
    }
    public function acceptedOffer(string $organizationId,int $dealId):?array
    {
        $row=$this->acceptedForDeal($organizationId,$dealId);
        if($row===null)return null;
        return ['offer_id'=>$row['offer_id'],'deal_id'=>(int)$row['deal_id'],'terms_version'=>(int)$row['terms_version'],
            'total'=>(string)$row['total'],'currency'=>$row['currency'],'accepted_at'=>$row['accepted_at'],'terms_hash'=>$row['terms_hash'],
            'evidence_kind'=>'operator_recorded_accepted_offer','settled'=>false];
    }
    public function create(string $org,string $id,int $deal,int $actor):void
    {
        $this->assertTransaction();
        $this->connection->prepare('INSERT INTO sales_offers(organization_id,offer_id,deal_id,status,revision,terms_version,created_by) VALUES(?,?,?,"draft",1,1,?)')->execute([$org,$id,$deal,$actor]);
    }
    public function appendTerms(string $org,string $id,int $version,array $terms,string $direction,int $actor):void
    {
        $this->assertTransaction();
        $this->connection->prepare('INSERT INTO sales_offer_revisions(organization_id,offer_id,terms_version,title,currency,total,valid_until,lines_json,direction,terms_hash,actor_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$org,$id,$version,$terms['title'],$terms['currency'],$terms['total'],$terms['valid_until'],json_encode($terms['lines'],JSON_THROW_ON_ERROR),
                $direction,hash('sha256',json_encode($terms,JSON_THROW_ON_ERROR)),$actor]);
    }
    public function advance(string $org,string $id,int $expectedRevision,int $termsVersion,string $status):void
    {
        $this->assertTransaction();
        $q=$this->connection->prepare('UPDATE sales_offers SET status=?,revision=revision+1,terms_version=?,accepted_at=CASE WHEN ?="accepted" THEN COALESCE(accepted_at,UTC_TIMESTAMP(6)) ELSE accepted_at END WHERE organization_id=? AND offer_id=? AND revision=?');
        $q->execute([$status,$termsVersion,$status,$org,$id,$expectedRevision]);
        if($q->rowCount()!==1)throw new DomainException('Offer revision conflict.');
    }
    public function appendHistory(string $org,string $id,int $revision,int $termsVersion,string $kind,?string $from,string $to,?string $evidence,?string $note,int $actor,string $trace):void
    {
        $this->assertTransaction();
        $this->connection->prepare('INSERT INTO sales_offer_negotiations(organization_id,offer_id,revision,terms_version,kind,from_status,to_status,evidence_reference,note,actor_id,correlation_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$org,$id,$revision,$termsVersion,$kind,$from,$to,$evidence,$note,$actor,$trace]);
    }
    public function history(string $org,string $id,int $limit=100):array
    {
        return $this->all('SELECT revision,terms_version,kind,from_status,to_status,evidence_reference,note,actor_id,correlation_id,created_at FROM sales_offer_negotiations WHERE organization_id=? AND offer_id=? ORDER BY revision DESC LIMIT '.max(1,min(100,$limit)),[$org,$id]);
    }
    public function termsHistory(string $org,string $id,int $limit=50):array
    {
        return $this->all('SELECT terms_version,title,currency,total,valid_until,lines_json,direction,terms_hash,actor_id,created_at FROM sales_offer_revisions WHERE organization_id=? AND offer_id=? ORDER BY terms_version DESC LIMIT '.max(1,min(50,$limit)),[$org,$id]);
    }
    private function one(string $sql,array $args):?array
    {
        $q=$this->connection->prepare($sql.($this->connection->inTransaction()?' FOR UPDATE':''));$q->execute($args);
        $row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    private function all(string $sql,array $args):array
    {
        $q=$this->connection->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    private function assertTransaction():void{if(!$this->connection->inTransaction())throw new \LogicException('Offer mutation requires an active transaction.');}
}
