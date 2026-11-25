<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\ReadModel\MySql;
use Domains\Sales\Application\Contract\SalesAttributionCandidateReadPort;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
final readonly class MysqlSalesAttributionCandidateReadModel implements SalesAttributionCandidateReadPort
{
    public function __construct(private PDO $connection){}
    public function page(string $org,string $kind,string $from,string $to,?string $after,int $limit):array
    {
        if($org===''||strlen($org)>40||$limit<1||$limit>50||!in_array($kind,['lead','order'],true))throw new DomainException('Invalid candidate query.');
        $dates=[];
        foreach([$from,$to] as $date){
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
            if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException('Invalid candidate date.');
            $dates[]=$d;
        }
        if($dates[0]>$dates[1]||$dates[0]->diff($dates[1])->days>90)throw new DomainException('Invalid candidate window.');
        $lead=$kind==='lead';$table=$lead?'tn_leads':'sales_orders';$id=$lead?'id':'order_id';$at=$lead?'created_at':'placed_at';
        if($after!==null&&!preg_match($lead?'/^[1-9][0-9]{0,17}$/D':'/^[a-f0-9]{32}$/D',$after))throw new DomainException('Invalid candidate cursor.');
        $sql="SELECT $id FROM $table WHERE organization_id=? AND BINARY organization_id=BINARY ? AND $at>=? AND $at<?";
        $args=[$org,$org,$from.' 00:00:00',$dates[1]->modify('+1 day')->format('Y-m-d').' 00:00:00'];
        if($after!==null){$sql.=" AND $id>?";$args[]=$lead?(int)$after:$after;}
        $q=$this->connection->prepare($sql." ORDER BY $id LIMIT ".($limit+1));$q->execute($args);
        $ids=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));$more=count($ids)>$limit;
        if($more)array_pop($ids);
        foreach($ids as $candidate)if(!preg_match($lead?'/^[1-9][0-9]{0,17}$/D':'/^[a-f0-9]{32}$/D',$candidate))throw new DomainException('Invalid stored candidate.');
        return ['ids'=>$ids,'next_after'=>$more?$ids[count($ids)-1]:null];
    }
}
