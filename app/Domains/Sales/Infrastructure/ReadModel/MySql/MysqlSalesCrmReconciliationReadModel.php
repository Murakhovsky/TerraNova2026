<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\ReadModel\MySql;
use Domains\Sales\Application\Contract\SalesCrmReconciliationReadPort;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use PDO;
final readonly class MysqlSalesCrmReconciliationReadModel implements SalesCrmReconciliationReadPort
{
    public function __construct(private PDO $connection,private ExternalReferenceStoreInterface $references){}
    public function reconcile(string $org,int $deal,string $provider,string $kind,string $key):array
    {
        $out=['deal_id'=>$deal,'provider'=>$provider,'kind'=>$kind,'state'=>'unresolved','native_id'=>null,'recipient_delivery_verified'=>false];
        $q=$this->connection->prepare('SELECT id FROM tn_client_cases WHERE organization_id=? AND id=?');$q->execute([$org,$deal]);
        if($q->fetchColumn()===false)return $out;
        if($provider!=='aida')return [...$out,'state'=>'unsupported_provider'];
        $id=$this->references->find($org,$provider,$kind,'action:'.$key);
        if($id===null)return $out;
        if(!ctype_digit($id)||(int)$id<1)return [...$out,'state'=>'conflict'];
        $q=$this->connection->prepare('SELECT id FROM tn_client_case_activities WHERE organization_id=? AND client_case_id=? AND id=? AND activity_type=?');$q->execute([$org,$deal,$id,$kind]);
        if($q->fetchColumn()===false)return [...$out,'state'=>'conflict'];
        if($kind==='message'){
            $q=$this->connection->prepare('SELECT COUNT(*) FROM sales_communications WHERE organization_id=? AND deal_id=? AND external_id=? AND direction="OUTBOUND"');
            $q->execute([$org,$deal,$id]);
            if((int)$q->fetchColumn()!==1)return [...$out,'state'=>'partial_local_record','native_id'=>(int)$id];
        }
        return [...$out,'state'=>'local_verified','native_id'=>(int)$id];
    }
}
