<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;
use Domains\Sales\Application\Contract\SalesOfferDeliveryRepositoryInterface;
use PDO;
final readonly class MysqlSalesOfferDeliveryRepository implements SalesOfferDeliveryRepositoryInterface
{
    public function __construct(private PDO $connection){}
    public function recipient(string $org,int $deal):?array
    {
        return $this->one('SELECT p.id,p.email,p.full_name FROM tn_client_cases d JOIN tn_people p ON p.id=d.person_id AND p.organization_id=d.organization_id WHERE d.organization_id=? AND d.id=?',[$org,$deal]);
    }
    public function get(string $org,string $offer,int $version):?array
    {
        return $this->one('SELECT notification_id,recipient_hash,document_hash,action_id FROM sales_offer_deliveries WHERE organization_id=? AND offer_id=? AND terms_version=?',[$org,$offer,$version]);
    }
    public function record(string $org,string $offer,int $version,string $notification,string $recipientHash,string $documentHash,string $action):void
    {
        if(!$this->connection->inTransaction())throw new \LogicException('Offer delivery requires transaction.');
        $this->connection->prepare('INSERT INTO sales_offer_deliveries(organization_id,offer_id,terms_version,notification_id,recipient_hash,document_hash,action_id) VALUES(?,?,?,?,?,?,?)')->execute([$org,$offer,$version,$notification,$recipientHash,$documentHash,$action]);
    }
    private function one(string $sql,array $args):?array
    {
        $q=$this->connection->prepare($sql.($this->connection->inTransaction()?' FOR UPDATE':''));$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
}
