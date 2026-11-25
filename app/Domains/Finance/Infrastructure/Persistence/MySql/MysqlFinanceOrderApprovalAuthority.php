<?php
declare(strict_types=1);
namespace Domains\Finance\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Finance\Application\Contract\FinanceOrderExecutionAuthority;
use Domains\Finance\Automation\Action\FinanceOrderPaymentActionHandler;
use Kernel\Action\Action;
use Kernel\Approval\Approval;
use Kernel\Approval\Contract\ApprovalAuthorityInterface;
use PDO;

/** Recheck the durable Kernel receipt and live manager authority inside the financial transaction. */
final readonly class MysqlFinanceOrderApprovalAuthority implements ApprovalAuthorityInterface,FinanceOrderExecutionAuthority
{
    public function __construct(private PDO $connection){}
    public function assertCanDecide(string $organizationId,string $userId,Approval $approval,Action $action):void
    {
        if(!in_array($action->type,[FinanceOrderPaymentActionHandler::REGISTER,FinanceOrderPaymentActionHandler::RECONCILE],true))return;
        if($action->organizationId!==$organizationId||$approval->organizationId!==$organizationId||$approval->actionId!==$action->id
            ||$action->sourceType!=='USER'||$action->sourceId===$userId||$approval->approverType!=='ROLE'||$approval->approverId!=='manager')
            throw new DomainException('Finance requires an independent tenant manager.');
        $this->manager($organizationId,$action->sourceId);$this->manager($organizationId,$userId);
    }
    public function assertApproved(string $organizationId,string $orderId,string $actionType,array $parameters,
        string $requester,string $actionId,string $correlationId,string $key):void
    {
        if(!$this->connection->inTransaction())throw new DomainException('Finance approval must be checked inside its write transaction.');
        $q=$this->connection->prepare('SELECT type,target_type,target_id,parameters,source_type,source_id,status,execution_mode,idempotency_key,correlation_id FROM cos_actions WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$organizationId,$actionId]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($a)||$a['type']!==$actionType||$a['target_type']!=='sales_order'||$a['target_id']!==$orderId
            ||$a['source_type']!=='USER'||(string)$a['source_id']!==$requester||$a['status']!=='RUNNING'
            ||$a['execution_mode']!=='APPROVAL_REQUIRED'||$a['idempotency_key']===null
            ||$a['idempotency_key']!==$key||$a['correlation_id']!==$correlationId)
            throw new DomainException('No matching canonical Finance Action in execution.');
        $stored=json_decode($a['parameters'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($stored))throw new DomainException('Invalid approved parameters.');
        ksort($stored);ksort($parameters);
        if($stored!==$parameters)throw new DomainException('Finance parameters differ from approved Action.');
        $q=$this->connection->prepare('SELECT decision FROM cos_policy_evaluations WHERE organization_id=? AND action_id=? ORDER BY evaluated_at DESC,id DESC LIMIT 1 FOR UPDATE');
        $q->execute([$organizationId,$actionId]);
        if($q->fetchColumn()!=='APPROVAL_REQUIRED')throw new DomainException('Independent Finance policy decision missing.');
        $q=$this->connection->prepare("SELECT status,approver_type,approver_id,requested_by_type,requested_by_id,decided_by_type,decided_by_id,decided_at,expires_at FROM cos_approvals WHERE organization_id=? AND action_id=? FOR UPDATE");
        $q->execute([$organizationId,$actionId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1)throw new DomainException('Unique Finance approval required.');
        $p=$rows[0];$approver=(string)$p['decided_by_id'];
        if($p['status']!=='APPROVED'||$p['approver_type']!=='ROLE'||$p['approver_id']!=='manager'
            ||$p['requested_by_type']!=='USER'||(string)$p['requested_by_id']!==$requester
            ||$p['decided_by_type']!=='USER'||$approver===$requester||$p['decided_at']===null
            ||($p['expires_at']!==null&&strtotime($p['expires_at'].' UTC')<=time()))
            throw new DomainException('Independent, unexpired Finance Approval missing.');
        $members=[$requester,$approver];sort($members,SORT_STRING);
        foreach($members as $member)$this->manager($organizationId,$member);
    }
    private function manager(string $org,string $user):void
    {
        if(!ctype_digit($user)||(int)$user<1||(string)(int)$user!==$user)throw new DomainException('Invalid Finance manager.');
        $q=$this->connection->prepare("SELECT 1 FROM cos_organization_memberships m JOIN tn_users u ON u.id=m.user_id WHERE m.organization_id=? AND m.user_id=? AND m.status='ACTIVE' AND m.role IN ('admin','manager') AND u.status='active' LIMIT 1".($this->connection->inTransaction()?' FOR UPDATE':''));
        $q->execute([$org,(int)$user]);if($q->fetchColumn()===false)throw new DomainException('Finance manager is no longer active.');
    }
}
