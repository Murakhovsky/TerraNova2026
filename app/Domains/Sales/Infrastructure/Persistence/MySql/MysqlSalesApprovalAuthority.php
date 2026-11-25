<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Model\SalesCapability;
use Kernel\Action\Action;
use Kernel\Approval\Approval;
use Kernel\Approval\Contract\ApprovalAuthorityInterface;
use PDO;

final readonly class MysqlSalesApprovalAuthority implements ApprovalAuthorityInterface, \Domains\Sales\Application\Contract\SalesOrderActionAuthority
{
    public function __construct(private PDO $connection) {}

    public function assertCanDecide(string $organizationId, string $userId, Approval $approval, Action $action): void
    {
        if (!str_starts_with($action->type, 'sales.')) {
            return;
        }

        if(str_starts_with($action->type,\Domains\Sales\Automation\Action\SalesGovernedOrderHandler::PREFIX)){
            if($approval->organizationId!==$organizationId||$approval->actionId!==$action->id||
                $approval->approverType!=='ROLE'||$approval->approverId!=='manager')
                throw new DomainException('Canonical Sales order approval required.');
            $this->assertGovernedOrderDecision($organizationId,$userId,$action);
            return;
        }

        $user = (int) $userId;
        if ($user <= 0 || !$this->activeMember($organizationId, $user)) {
            throw new DomainException('Approval actor is not an active organization member.');
        }

        if ($this->hasCapability($organizationId, $user, SalesCapability::ApprovalAnyTeam->value)) {
            return;
        }

        if (!$this->hasCapability($organizationId, $user, SalesCapability::ApprovalDecide->value)
            && !$this->isApprovalLead($organizationId, $user)) {
            throw new DomainException('User does not have Sales approval authority.');
        }

        if ($action->targetType !== 'deal' || $action->targetId === null || !ctype_digit($action->targetId)) {
            return;
        }

        $statement = $this->connection->prepare(
            'SELECT 1
             FROM tn_client_cases deal
             INNER JOIN sales_team_members owner_member
                ON owner_member.organization_id = deal.organization_id
               AND owner_member.user_id = deal.assigned_user_id
               AND owner_member.status = "ACTIVE"
             INNER JOIN sales_team_members approver_member
                ON approver_member.organization_id = owner_member.organization_id
               AND approver_member.team_id = owner_member.team_id
               AND approver_member.user_id = :user_id
               AND approver_member.status = "ACTIVE"
               AND approver_member.approval_enabled = 1
             INNER JOIN sales_teams team
                ON team.organization_id = owner_member.organization_id
               AND team.id = owner_member.team_id
               AND team.status = "ACTIVE"
             WHERE deal.organization_id = :organization_id AND deal.id = :deal_id
             LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_id' => (int) $action->targetId,
            'user_id' => $user,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('User cannot approve Sales actions outside their team.');
        }
    }

    public function assertApprovedOrderExecution(Action $action):void
    {
        if(!$this->connection->inTransaction()||!in_array($action->type,\Domains\Sales\Automation\Action\SalesGovernedOrderHandler::types(),true))
            throw new DomainException('Sales order approval requires its native transaction.');
        $q=$this->connection->prepare('SELECT type,target_type,target_id,parameters,source_type,source_id,status,execution_mode,risk_level,idempotency_key,correlation_id
            FROM cos_actions WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$action->organizationId,$action->id]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($a)||$a['type']!==$action->type||$a['target_type']!==$action->targetType||$a['target_id']!==$action->targetId||
            $a['source_type']!=='USER'||(string)$a['source_id']!==$action->sourceId||$a['status']!=='RUNNING'||
            $a['execution_mode']!=='APPROVAL_REQUIRED'||$a['risk_level']!=='HIGH'||$a['idempotency_key']!==$action->idempotencyKey||
            $a['correlation_id']!==$action->correlationId||self::canonical(json_decode($a['parameters'],true,512,JSON_THROW_ON_ERROR))!==self::canonical($action->parameters))
            throw new DomainException('Canonical Sales order Action changed.');
        $q=$this->connection->prepare('SELECT decision FROM cos_policy_evaluations WHERE organization_id=? AND action_id=? ORDER BY evaluated_at DESC,id DESC LIMIT 1 FOR UPDATE');
        $q->execute([$action->organizationId,$action->id]);
        if($q->fetchColumn()!=='APPROVAL_REQUIRED')throw new DomainException('Sales order Policy approval missing.');
        $q=$this->connection->prepare('SELECT status,approver_type,approver_id,requested_by_type,requested_by_id,decided_by_type,decided_by_id,decided_at,expires_at
            FROM cos_approvals WHERE organization_id=? AND action_id=? FOR UPDATE');
        $q->execute([$action->organizationId,$action->id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1)throw new DomainException('Unique Sales order Approval required.');
        $p=$rows[0];
        if($p['status']!=='APPROVED'||$p['approver_type']!=='ROLE'||$p['approver_id']!=='manager'||
            $p['requested_by_type']!=='USER'||(string)$p['requested_by_id']!==$action->sourceId||
            $p['decided_by_type']!=='USER'||$p['decided_at']===null||
            ($p['expires_at']!==null&&strtotime($p['expires_at'].' UTC')<=time()))
            throw new DomainException('Independent unexpired Sales order Approval required.');
        $this->assertGovernedOrderDecision($action->organizationId,(string)$p['decided_by_id'],$action);
    }

    private function assertGovernedOrderDecision(string $org,string $userId,Action $action):void
    {
        if($action->organizationId!==$org||$action->sourceType!=='USER'||$action->sourceId===$userId||
            !in_array($action->type,\Domains\Sales\Automation\Action\SalesGovernedOrderHandler::types(),true)||
            $action->executionMode!=='APPROVAL_REQUIRED'||!is_string($action->idempotencyKey)||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey))
            throw new DomainException('Sales order requires independent canonical Workflow approval.');
        $users=[$action->sourceId,$userId];sort($users,SORT_STRING);
        foreach($users as $uid){
            if(!ctype_digit($uid)||(int)$uid<1||(string)(int)$uid!==$uid)throw new DomainException('Invalid Sales manager.');
            $q=$this->connection->prepare('SELECT 1 FROM cos_organization_memberships m JOIN tn_users u ON u.id=m.user_id AND u.organization_id=m.organization_id
                WHERE m.organization_id=? AND m.user_id=? AND m.status="ACTIVE" AND m.role IN ("manager","admin") AND u.status="active" LIMIT 1'.
                ($this->connection->inTransaction()?' FOR UPDATE':''));
            $q->execute([$org,(int)$uid]);if($q->fetchColumn()===false)throw new DomainException('Sales order manager inactive.');
        }
        $approver=(int)$userId;$any=$this->hasCapability($org,$approver,SalesCapability::ApprovalAnyTeam->value);
        if(!$any&&!$this->hasCapability($org,$approver,SalesCapability::ApprovalDecide->value)&&!$this->isApprovalLead($org,$approver))
            throw new DomainException('Sales order approval authority missing.');
        $params=$action->parameters;
        if($action->type===\Domains\Sales\Automation\Action\SalesGovernedOrderHandler::PREFIX.'create'){
            if($action->targetType!=='sales_order_intent')throw new DomainException('Order intent target missing.');
            $owner=$params['owner_id']??(int)$action->sourceId;$deal=$params['deal_id']??null;
        }else{
            if($action->targetType!=='sales_order'||($params['order_id']??null)!==$action->targetId)throw new DomainException('Order approval target mismatch.');
            $q=$this->connection->prepare('SELECT owner_user_id,deal_id FROM sales_orders WHERE organization_id=? AND order_id=?'.
                ($this->connection->inTransaction()?' FOR UPDATE':''));
            $q->execute([$org,$action->targetId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new DomainException('Order approval target unavailable.');
            $owner=(int)$row['owner_user_id'];$deal=$row['deal_id']===null?null:(int)$row['deal_id'];
        }
        if(!is_int($owner)||$owner<1)throw new DomainException('Order approval owner invalid.');
        $this->assertOrderOwnerApproval($org,$approver,$owner,$any);
        if($deal!==null){
            if(!is_int($deal)||$deal<1)throw new DomainException('Order approval deal invalid.');
            $q=$this->connection->prepare('SELECT assigned_user_id FROM tn_client_cases WHERE organization_id=? AND id=?'.
                ($this->connection->inTransaction()?' FOR UPDATE':''));
            $q->execute([$org,$deal]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new DomainException('Order approval deal unavailable.');
            $this->assertOrderOwnerApproval($org,$approver,(int)$row['assigned_user_id'],$any);
        }
    }

    private function assertOrderOwnerApproval(string $org,int $approver,int $owner,bool $any):void
    {
        if($any||$owner===$approver)return;
        if($owner<1)throw new DomainException('Unassigned order requires any-team approval.');
        $q=$this->connection->prepare('SELECT 1 FROM sales_team_members m JOIN sales_teams t ON t.organization_id=m.organization_id AND t.id=m.team_id AND t.status="ACTIVE"
            JOIN sales_team_members a ON a.organization_id=m.organization_id AND a.team_id=m.team_id AND a.status="ACTIVE" AND a.approval_enabled=1
            WHERE m.organization_id=? AND m.user_id=? AND m.status="ACTIVE" AND a.user_id=? LIMIT 1'.
            ($this->connection->inTransaction()?' FOR UPDATE':''));
        $q->execute([$org,$owner,$approver]);
        if($q->fetchColumn()===false)throw new DomainException('Order approver outside authorized team.');
    }

    private static function canonical(mixed $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{
            if(!is_array($v))return $v;
            if(!array_is_list($v))ksort($v,SORT_STRING);
            foreach($v as $k=>$item)$v[$k]=$sort($item);
            return $v;
        };
        return json_encode($sort($value),JSON_THROW_ON_ERROR);
    }

    private function activeMember(string $organizationId, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM cos_organization_memberships
             WHERE organization_id = :organization_id AND user_id = :user_id AND status = "ACTIVE" LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    private function isApprovalLead(string $organizationId, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM sales_team_members member
             INNER JOIN sales_teams team ON team.id = member.team_id AND team.organization_id = member.organization_id
             WHERE member.organization_id = :organization_id AND member.user_id = :user_id
               AND member.status = "ACTIVE" AND member.role = "LEAD" AND member.approval_enabled = 1
               AND team.status = "ACTIVE" LIMIT 1'.($this->connection->inTransaction()?' FOR UPDATE':'')
        );
        $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    private function hasCapability(string $organizationId, int $userId, string $capability): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sales_user_capabilities
             WHERE organization_id = :organization_id AND user_id = :user_id
               AND capability = :capability AND status = "ACTIVE" LIMIT 1'.($this->connection->inTransaction()?' FOR UPDATE':'')
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'capability' => $capability,
        ]);
        return $statement->fetchColumn() !== false;
    }
}
