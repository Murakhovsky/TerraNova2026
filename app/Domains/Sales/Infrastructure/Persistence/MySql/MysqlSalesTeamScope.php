<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesTeamScopeInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use DomainException;
use PDO;

final readonly class MysqlSalesTeamScope implements SalesTeamScopeInterface
{
    public function __construct(private PDO $connection,private SalesWorkspaceScopeInterface $scope,private SalesAccessControlInterface $access) {}

    public function members(string $org,int $actor,string $team):array
    {
        $this->scope->resolve($org,$actor,'self',null);
        if (preg_match('/^[A-Za-z0-9_-]{8,40}$/D',$team)!==1) throw new DomainException('Sales team unavailable.');
        $director=$this->access->hasCapability($org,$actor,'sales.director.view');
        $q=$this->connection->prepare('SELECT 1 FROM sales_teams t WHERE t.organization_id=? AND t.id=? AND t.status="ACTIVE"'
            .($director?'':' AND EXISTS(SELECT 1 FROM sales_team_members a WHERE a.organization_id=t.organization_id AND a.team_id=t.id AND a.user_id=? AND a.role="LEAD" AND a.status="ACTIVE")'));
        $q->execute($director?[$org,$team]:[$org,$team,$actor]);
        if ($q->fetchColumn()===false) throw new DomainException('Sales team unavailable.');
        $q=$this->connection->prepare('SELECT DISTINCT m.user_id FROM sales_team_members m
            JOIN tn_users u ON u.organization_id=m.organization_id AND u.id=m.user_id AND u.status="active"
            JOIN cos_organization_memberships om ON om.organization_id=m.organization_id AND om.user_id=m.user_id AND om.status="ACTIVE" AND om.role IN ("manager","admin")
            WHERE m.organization_id=? AND m.team_id=? AND m.status="ACTIVE" ORDER BY m.user_id LIMIT 1001');
        $q->execute([$org,$team]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids)>1000) throw new DomainException('Sales team exceeds supported report bound.');
        return $ids;
    }
}
