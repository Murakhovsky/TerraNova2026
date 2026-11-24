<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Model\SalesCapability;
use DomainException;
use PDO;

final readonly class MysqlSalesWorkspaceScope implements SalesWorkspaceScopeInterface
{
    public function __construct(private PDO $connection,private SalesAccessControlInterface $access){}
    public function resolve(string $org,int $actor,string $scope,?int $owner):?int
    {
        if($org===''||$actor<1||!$this->active($org,$actor))throw new DomainException('Active Sales membership required.');
        if($scope==='self'&&$owner===null)return $actor;
        $director=$this->access->hasCapability($org,$actor,SalesCapability::DirectorView->value);
        if($scope==='tenant'&&$owner===null&&$director)return null;
        if($scope!=='owner'||$owner===null||$owner<1||!$this->active($org,$owner))throw new DomainException('Sales workspace scope denied.');
        if($owner===$actor||$director)return $owner;
        $q=$this->connection->prepare('SELECT 1 FROM sales_team_members a JOIN sales_teams t ON t.id=a.team_id AND t.organization_id=a.organization_id AND t.status="ACTIVE"
            JOIN sales_team_members m ON m.team_id=a.team_id AND m.organization_id=a.organization_id AND m.status="ACTIVE" AND m.user_id=?
            WHERE a.organization_id=? AND a.user_id=? AND a.status="ACTIVE" AND a.role="LEAD" LIMIT 1');
        $q->execute([$owner,$org,$actor]);
        if($q->fetchColumn()===false)throw new DomainException('Sales owner outside authorized team scope.');
        return $owner;
    }
    private function active(string $org,int $user):bool
    {
        $q=$this->connection->prepare('SELECT 1 FROM tn_users u JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status="ACTIVE" WHERE u.organization_id=? AND u.id=? AND u.status="active" AND m.role IN ("manager","admin") LIMIT 1');
        $q->execute([$org,$user]);return $q->fetchColumn()!==false;
    }
}
