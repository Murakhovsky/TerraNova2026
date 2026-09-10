<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use PDO;
use PDOException;

/**
 * EPIC 2 projection adapter.
 *
 * It deliberately composes the stable Sales read model instead of widening it.
 * Sales owns the business-facing projection while Kernel still owns actions,
 * approvals, execution and audit state.
 */
final readonly class MysqlSalesWorkspaceOperationalReadModel implements SalesWorkspaceOperationalReadModelInterface
{
    public function __construct(private PDO $connection, private SalesWorkspaceReadModelInterface $base) {}

    public function dashboard(string $organizationId, ?int $ownerId = null): array
    {
        $data = $this->base->dashboard($organizationId, $ownerId);
        $data['today'] = $this->today($organizationId, $ownerId ?? 0);
        $data['at_risk'] = $this->deals($organizationId, ['owner_id' => $ownerId, 'risk' => 'high', 'limit' => 8]);
        $data['new_leads'] = $this->leads($organizationId, ['owner_id' => $ownerId, 'status' => 'new', 'limit' => 8]);
        return $data;
    }

    public function leads(string $organizationId, array $filters = []): array
    {
        $where = ['l.organization_id = :organization_id'];
        $params = ['organization_id' => $organizationId];
        if (($filters['status'] ?? '') !== '') { $where[] = 'l.status = :status'; $params['status'] = (string) $filters['status']; }
        if (($filters['source'] ?? '') !== '') { $where[] = 'l.source_page = :source'; $params['source'] = (string) $filters['source']; }
        if (($filters['q'] ?? '') !== '') { $where[] = '(l.full_name LIKE :q OR l.email LIKE :q OR l.phone LIKE :q)'; $params['q'] = '%' . trim((string) $filters['q']) . '%'; }
        $ownerId = (int) ($filters['owner_id'] ?? 0);
        if ($ownerId > 0) { $where[] = 'l.assigned_user_id = :owner_id'; $params['owner_id'] = $ownerId; }
        if (!empty($filters['unassigned'])) $where[] = 'l.assigned_user_id IS NULL';
        $limit = $this->limit($filters['limit'] ?? 100);
        return $this->all(
            'SELECT l.id, l.full_name name, l.phone, l.email, l.message, l.manager_note, l.source_page source, l.status, '
            . 'l.assigned_user_id owner_id, u.full_name owner_name, l.created_at, l.last_contacted_at last_contact_at, '
            . 'l.next_contact_at next_action_at, l.client_case_id deal_id, TIMESTAMPDIFF(HOUR, l.created_at, NOW()) age_hours, '
            . 'CASE WHEN l.assigned_user_id IS NULL THEN "UNASSIGNED" WHEN l.next_contact_at IS NOT NULL AND l.next_contact_at < NOW() THEN "FOLLOW_UP_OVERDUE" WHEN l.status = "new" THEN "NEW_LEAD" WHEN l.last_contacted_at IS NULL THEN "NOT_CONTACTED" ELSE NULL END attention_reason, '
            . 'CASE WHEN l.next_contact_at < NOW() THEN "HIGH" WHEN l.status = "new" THEN "MEDIUM" ELSE "NORMAL" END ai_priority '
            . 'FROM tn_leads l LEFT JOIN tn_users u ON u.id = l.assigned_user_id AND u.organization_id = l.organization_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC LIMIT ' . $limit,
            $params
        );
    }

    public function deals(string $organizationId, array $filters = []): array
    {
        $where = ['c.organization_id = :organization_id']; $params = ['organization_id' => $organizationId];
        foreach (['status'=>'c.status','stage_id'=>'c.stage_id','pipeline_id'=>'c.pipeline_id','priority'=>'c.priority','source'=>'c.source'] as $key=>$column) {
            if (($filters[$key] ?? '') !== '') { $where[] = $column . ' = :' . $key; $params[$key] = (string) $filters[$key]; }
        }
        if (($filters['q'] ?? '') !== '') { $where[] = '(c.title LIKE :q OR c.public_id LIKE :q OR p.full_name LIKE :q)'; $params['q'] = '%' . trim((string) $filters['q']) . '%'; }
        $ownerId = (int) ($filters['owner_id'] ?? 0); if ($ownerId > 0) { $where[] = 'c.assigned_user_id = :owner_id'; $params['owner_id'] = $ownerId; }
        if (($filters['risk'] ?? '') === 'high') $where[] = '(c.priority IN ("high", "urgent") OR c.next_contact_at < NOW() OR c.last_activity_at < NOW() - INTERVAL 48 HOUR)';
        return $this->all(
            'SELECT c.id,c.public_id,c.title,c.status,c.priority,c.source,c.pipeline_id,c.stage_id,COALESCE(s.code,UPPER(c.stage)) stage_code,COALESCE(s.name,c.stage) stage_name,s.sort_order stage_order,p.full_name customer,c.assigned_user_id owner_id,u.full_name owner_name,COALESCE(c.deal_value,c.budget_max) deal_value,c.currency,COALESCE(c.probability,s.probability_default,0) probability,ROUND(COALESCE(c.deal_value,c.budget_max,0)*COALESCE(c.probability,s.probability_default,0)/100,2) weighted_value,c.last_activity_at,c.next_contact_at next_action_at,c.expected_close_at,c.created_at,c.updated_at,TIMESTAMPDIFF(DAY,c.updated_at,NOW()) days_in_stage,CASE WHEN c.next_contact_at IS NOT NULL AND c.next_contact_at<NOW() THEN "FOLLOW_UP_OVERDUE" WHEN c.priority IN ("high","urgent") THEN "HIGH_PRIORITY" WHEN c.last_activity_at IS NULL THEN "NO_ACTIVITY" WHEN c.last_activity_at<NOW()-INTERVAL 48 HOUR THEN "NO_ACTIVITY_48H" WHEN c.next_contact_at IS NULL THEN "NO_NEXT_ACTION" ELSE NULL END attention_reason,CASE WHEN c.priority IN ("high","urgent") OR c.next_contact_at<NOW() OR c.last_activity_at<NOW()-INTERVAL 48 HOUR THEN "HIGH" ELSE "NORMAL" END risk_level FROM tn_client_cases c INNER JOIN tn_people p ON p.id=c.person_id AND p.organization_id=c.organization_id LEFT JOIN tn_users u ON u.id=c.assigned_user_id AND u.organization_id=c.organization_id LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(s.sort_order,0),c.updated_at DESC LIMIT ' . $this->limit($filters['limit'] ?? 100),
            $params
        );
    }

    public function deal(string $organizationId, int $dealId): ?array
    {
        $deal=$this->base->deal($organizationId,$dealId); if($deal===null)return null;
        $deal['days_in_stage']=max(0,(int)floor((time()-strtotime((string)($deal['updated_at']??'now')))/86400));
        $deal['weighted_value']=round((float)($deal['value']??0)*(float)($deal['probability']??0)/100,2);
        $deal['attention_reason']=$this->attentionReason($deal); return $deal;
    }
    public function timeline(string $organizationId,int $dealId,int $limit=100):array{return $this->base->timeline($organizationId,$dealId,$limit);}
    public function metrics(string $organizationId,int $days=30):array{return $this->base->metrics($organizationId,$days);}

    public function pipelines(string $organizationId): array
    {
        $pipelines=$this->base->pipelines($organizationId);
        foreach($pipelines as &$pipeline){$deals=$this->deals($organizationId,['pipeline_id'=>(string)($pipeline['id']??''),'limit'=>250]);foreach($pipeline['stages']??[] as &$stage){$stageDeals=array_values(array_filter($deals,static fn(array $deal):bool=>(string)($deal['stage_id']??'')===(string)($stage['id']??'')));$stage['deal_count']=count($stageDeals);$stage['pipeline_value']=array_sum(array_map(static fn(array $deal):float=>(float)($deal['deal_value']??0),$stageDeals));$stage['weighted_value']=array_sum(array_map(static fn(array $deal):float=>(float)($deal['weighted_value']??0),$stageDeals));$stage['avg_days_in_stage']=$stageDeals===[]?0:round(array_sum(array_map(static fn(array $deal):int=>(int)($deal['days_in_stage']??0),$stageDeals))/count($stageDeals),1);}unset($stage);}unset($pipeline);return $pipelines;
    }

    public function today(string $organizationId,int $ownerId):array
    {
        $today=$this->base->today($organizationId,$ownerId);foreach($today as &$items){foreach($items as &$item){$item['attention_reason']??=$this->attentionReason($item);}unset($item);}unset($items);$today['needs_approval']=$this->approvals($organizationId,null,$ownerId>0?$ownerId:null,20);return $today;
    }

    public function communications(string $organizationId,int $dealId,int $limit=50):array
    {
        return $this->safeAll('SELECT id,deal_id,person_id,channel,direction,sender,recipient,body,external_id,metadata,occurred_at FROM sales_communications WHERE organization_id=:organization_id AND deal_id=:deal_id ORDER BY occurred_at DESC LIMIT '.$this->limit($limit),['organization_id'=>$organizationId,'deal_id'=>$dealId]);
    }

    public function approvals(string $organizationId,?int $dealId=null,?int $ownerId=null,int $limit=50):array
    {
        $where=['ap.organization_id=:organization_id','ap.status="PENDING"','a.organization_id=ap.organization_id'];$params=['organization_id'=>$organizationId];
        if($dealId!==null){$where[]='a.target_type IN ("deal","client_case") AND a.target_id=:deal_id';$params['deal_id']=(string)$dealId;}
        if($ownerId!==null&&$ownerId>0){$where[]='(ap.approver_type<>"USER" OR ap.approver_id=:owner_id)';$params['owner_id']=(string)$ownerId;}
        return $this->safeAll('SELECT ap.id approval_id,ap.action_id,ap.approver_type,ap.approver_id,ap.reason approval_reason,a.type action_type,a.target_id deal_id,a.status action_status,a.risk_level,a.parameters,a.created_at,c.public_id,c.title,p.full_name customer FROM cos_approvals ap INNER JOIN cos_actions a ON a.id=ap.action_id LEFT JOIN tn_client_cases c ON c.organization_id=ap.organization_id AND c.id=CAST(a.target_id AS UNSIGNED) LEFT JOIN tn_people p ON p.organization_id=c.organization_id AND p.id=c.person_id WHERE '.implode(' AND ',$where).' ORDER BY a.created_at DESC LIMIT '.$this->limit($limit),$params);
    }

    public function directorAnalytics(string $organizationId,int $days=30):array
    {
        $days=max(1,min($days,365));$deals=$this->deals($organizationId,['limit'=>250]);$active=array_values(array_filter($deals,static fn(array $deal):bool=>in_array((string)($deal['status']??''),['active','paused'],true)));$managers=[];$stageCounts=[];
        foreach($deals as $deal){$ownerKey=(string)((int)($deal['owner_id']??0));$managers[$ownerKey]??=['owner_id'=>(int)($deal['owner_id']??0),'owner_name'=>$deal['owner_name']?:'Unassigned','deals'=>0,'pipeline_value'=>0.0,'at_risk'=>0,'without_next_action'=>0];$managers[$ownerKey]['deals']++;$managers[$ownerKey]['pipeline_value']+=(float)($deal['deal_value']??0);if(($deal['risk_level']??'')==='HIGH')$managers[$ownerKey]['at_risk']++;if(empty($deal['next_action_at']))$managers[$ownerKey]['without_next_action']++;$stage=(string)($deal['stage_name']??$deal['stage_code']??'Unknown');$stageCounts[$stage]=($stageCounts[$stage]??0)+1;}
        return ['funnel'=>['kind'=>'current_state_cohort','stages'=>$stageCounts],'pipeline_health'=>['active_deals'=>count($active),'at_risk'=>count(array_filter($active,static fn(array $deal):bool=>($deal['risk_level']??'')==='HIGH')),'without_next_action'=>count(array_filter($active,static fn(array $deal):bool=>empty($deal['next_action_at']))),'weighted_value'=>array_sum(array_map(static fn(array $deal):float=>(float)($deal['weighted_value']??0),$active))],'manager_performance'=>array_values($managers),'pending_approvals'=>count($this->approvals($organizationId,null,null,250)),'window_days'=>$days];
    }

    private function attentionReason(array $row):?string{$next=$row['next_action_at']??$row['next_contact_at']??$row['due_at']??null;if($next&&strtotime((string)$next)<time())return 'FOLLOW_UP_OVERDUE';if(in_array(strtolower((string)($row['priority']??'')),['high','urgent'],true))return 'HIGH_PRIORITY';if(empty($row['last_activity_at'])&&empty($row['last_contact_at']))return 'NO_ACTIVITY';$last=$row['last_activity_at']??$row['last_contact_at']??null;if($last&&strtotime((string)$last)<time()-172800)return 'NO_ACTIVITY_48H';if($next===null&&isset($row['id']))return 'NO_NEXT_ACTION';return null;}
    private function limit(mixed $value):int{return max(1,min((int)$value,250));}
    private function all(string $sql,array $params=[]):array{$statement=$this->connection->prepare($sql);$statement->execute($params);return $statement->fetchAll(PDO::FETCH_ASSOC);}
    private function safeAll(string $sql,array $params=[]):array{try{return $this->all($sql,$params);}catch(PDOException){return [];}}
}
