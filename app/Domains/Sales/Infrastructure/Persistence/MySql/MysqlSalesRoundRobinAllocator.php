<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\InboundLeadAssignment;
use PDO;
use LogicException;

final readonly class MysqlSalesRoundRobinAllocator implements InboundLeadAssignment
{
    public function __construct(private PDO $db) {}
    public function allocate(string $org): ?int
    {
        if(!$this->db->inTransaction())throw new LogicException('Allocation requires intake transaction.');
        $q=$this->db->prepare('SELECT id,configuration_version FROM sales_teams WHERE organization_id=? AND code="DEFAULT" AND status="ACTIVE" AND assignment_mode="ROUND_ROBIN" FOR UPDATE');
        $q->execute([$org]);$team=$q->fetch(PDO::FETCH_ASSOC);
        if(!$team)return null;
        $q=$this->db->prepare('SELECT m.user_id,m.assignment_weight FROM sales_team_members m JOIN tn_users u ON u.id=m.user_id AND u.organization_id=m.organization_id AND u.status="active" JOIN cos_organization_memberships om ON om.organization_id=m.organization_id AND om.user_id=m.user_id AND om.status="ACTIVE" AND om.role IN ("manager","admin") JOIN sales_user_capabilities c ON c.organization_id=m.organization_id AND c.user_id=m.user_id AND c.capability="sales.workspace.use" AND c.status="ACTIVE" WHERE m.organization_id=? AND m.team_id=? AND m.status="ACTIVE" AND m.assignment_enabled=1 ORDER BY m.user_id FOR UPDATE');
        $q->execute([$org,$team['id']]);$pool=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$weight=(int)$row['assignment_weight'];if($weight>=1&&$weight<=100)$pool[]=[(int)$row['user_id'],$weight];}
        if(!$pool)return null;
        $hash=hash('sha256',json_encode([(int)$team['configuration_version'],$pool],JSON_THROW_ON_ERROR));
        $total=array_sum(array_column($pool,1));
        $q=$this->db->prepare('SELECT pool_hash,next_slot FROM sales_round_robin_cursors WHERE organization_id=? AND team_id=? FOR UPDATE');
        $q->execute([$org,$team['id']]);$cursor=$q->fetch(PDO::FETCH_ASSOC);
        $slot=$cursor&&hash_equals($hash,$cursor['pool_hash'])?(int)$cursor['next_slot']%$total:0;
        $index=$slot;$owner=null;
        foreach($pool as [$id,$weight]){if($index<$weight){$owner=$id;break;}$index-=$weight;}
        if($owner===null)throw new LogicException('Invalid assignment cursor.');
        $q=$this->db->prepare('INSERT INTO sales_round_robin_cursors(organization_id,team_id,pool_hash,next_slot) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE pool_hash=VALUES(pool_hash),next_slot=VALUES(next_slot)');
        $q->execute([$org,$team['id'],$hash,($slot+1)%$total]);
        return $owner;
    }
}
