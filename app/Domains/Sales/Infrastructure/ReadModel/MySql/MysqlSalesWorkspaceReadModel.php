<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use PDO;
use Domains\Sales\Application\Contract\SalesWorkspaceSummaryReadPort;
use Domains\Sales\Application\Contract\SalesTeamSummaryReadPort;

final readonly class MysqlSalesWorkspaceReadModel implements SalesWorkspaceReadModelInterface, SalesProposalLeadReadModelInterface, SalesWorkspaceSummaryReadPort, SalesTeamSummaryReadPort, \Domains\Sales\Application\Contract\SalesTodayPopulationReadPort
{
    public function __construct(private PDO $connection)
    {
    }

    public function dashboard(string $organizationId, ?int $ownerId = null): array
    {
        $summary=$this->summary($organizationId,$ownerId);
        $kpis=$summary['counts'];
        $kpis['money_by_currency']=$summary['money_by_currency'];
        // Retain compatibility scalars only for a single known currency; no implicit FX.
        $single=count($summary['money_by_currency'])===1?$summary['money_by_currency'][0]:null;
        $kpis['pipeline_value']=$single['pipeline_value']??null;
        $kpis['expected_revenue']=$single['weighted_value']??null;
        $kpis['currency']=$single['currency']??null;

        return [
            'kpis' => $kpis,
            'today' => $this->today($organizationId, $ownerId ?? 0),
            'at_risk' => $this->deals($organizationId, ['owner_id' => $ownerId, 'risk' => 'high', 'open_only'=>true, 'limit' => 8]),
            'new_leads' => $this->leads($organizationId, ['owner_id' => $ownerId, 'status' => 'new', 'limit' => 8]),
            'metrics' => $ownerId===null?$this->metrics($organizationId):[],
        ];
    }

    public function summary(string $organizationId,?int $ownerId=null,?string $pipelineId=null):array
    {
        return $this->summaryPopulation($organizationId,$ownerId,$pipelineId,null);
    }

    public function summaryForOwners(string $org,array $owners,?string $pipeline=null):array
    {
        if (!array_is_list($owners)||count($owners)>1000) throw new \DomainException('Invalid team owner population.');
        foreach ($owners as $id) if (!is_int($id)||$id<1) throw new \DomainException('Invalid team owner identity.');
        return $this->summaryPopulation($org,null,$pipeline,array_values(array_unique($owners)));
    }

    private function summaryPopulation(string $organizationId,?int $ownerId,?string $pipelineId,?array $owners):array
    {
        if($organizationId===''||($ownerId!==null&&$ownerId<1))throw new \DomainException('Invalid summary scope.');
        $where=['c.organization_id=:org','c.status IN ("active","paused")'];$params=['org'=>$organizationId];
        if($ownerId!==null){$where[]='c.assigned_user_id=:owner';$params['owner']=$ownerId;}
        if($pipelineId!==null){$where[]='c.pipeline_id=:pipeline';$params['pipeline']=$pipelineId;}
        if ($owners!==null) {
            if ($owners===[]) $where[]='1=0';
            else {
                $slots=[];
                foreach ($owners as $i=>$id) {$key='team_owner_'.$i;$slots[]=':'.$key;$params[$key]=$id;}
                $where[]='c.assigned_user_id IN ('.implode(',',$slots).')';
            }
        }
        $from=' FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id AND s.pipeline_id=c.pipeline_id WHERE '.implode(' AND ',$where);
        $known='c.deal_value IS NOT NULL AND c.deal_value>=0 AND UPPER(c.currency) IN ("USD","EUR","UAH")';
        $prob='COALESCE(c.probability,s.probability_default)';
        $valid=$known.' AND '.$prob.' BETWEEN 0 AND 100';
        $risk='(c.priority IN ("high","urgent") OR c.next_contact_at<NOW() OR c.last_activity_at IS NULL OR c.last_activity_at<NOW()-INTERVAL 48 HOUR)';
        $counts=$this->one('SELECT COUNT(*) active_deals,COALESCE(SUM(c.next_contact_at<NOW()),0) followups_overdue,COALESCE(SUM('.$risk.'),0) deals_at_risk,COALESCE(SUM(c.next_contact_at IS NULL),0) without_next_action,COALESCE(SUM(CASE WHEN '.$known.' THEN 1 ELSE 0 END),0) monetary_deals,COALESCE(SUM(CASE WHEN '.$valid.' THEN 1 ELSE 0 END),0) weighted_deals'.$from,$params)??[];
        $moneySelect='COUNT(*) deal_count,CAST(SUM(c.deal_value) AS DECIMAL(30,2)) pipeline_value,CAST(ROUND(SUM(CASE WHEN '.$valid.' THEN c.deal_value*'.$prob.'/100 ELSE NULL END),2) AS DECIMAL(30,2)) weighted_value,SUM(CASE WHEN '.$valid.' THEN 1 ELSE 0 END) weighted_count';
        $money=$this->all('SELECT UPPER(c.currency) currency,'.$moneySelect.$from.' AND '.$known.' GROUP BY UPPER(c.currency) ORDER BY UPPER(c.currency)',$params);
        $stageRows=$this->all('SELECT c.pipeline_id,c.stage_id,COUNT(*) deal_count,COALESCE(SUM(CASE WHEN '.$known.' THEN 1 ELSE 0 END),0) monetary_deals'.$from.' GROUP BY c.pipeline_id,c.stage_id ORDER BY c.pipeline_id,c.stage_id',$params);
        $stageMoney=$this->all('SELECT c.pipeline_id,c.stage_id,UPPER(c.currency) currency,'.$moneySelect.$from.' AND '.$known.' GROUP BY c.pipeline_id,c.stage_id,UPPER(c.currency) ORDER BY c.pipeline_id,c.stage_id,UPPER(c.currency)',$params);
        $normal=static fn(array $row):array=>['currency'=>(string)$row['currency'],'deal_count'=>(int)$row['deal_count'],'pipeline_value'=>(string)$row['pipeline_value'],'weighted_value'=>$row['weighted_value']===null?null:(string)$row['weighted_value'],'weighted_count'=>(int)$row['weighted_count']];
        $stages=[];foreach($stageRows as $row){
            $by=[];foreach($stageMoney as $item)if($item['pipeline_id']===$row['pipeline_id']&&$item['stage_id']===$row['stage_id'])$by[]=$normal($item);
            $stages[]=['pipeline_id'=>$row['pipeline_id'],'stage_id'=>$row['stage_id'],'deal_count'=>(int)$row['deal_count'],'monetary_deals'=>(int)$row['monetary_deals'],'money_by_currency'=>$by];
        }
        return ['scope'=>['owner_id'=>$ownerId,'pipeline_id'=>$pipelineId],'counts'=>array_map('intval',$counts),'money_by_currency'=>array_map($normal,$money),'stages'=>$stages];
    }

    public function leads(string $organizationId, array $filters = []): array
    {
        $where = ['l.organization_id = :organization_id'];
        $params = ['organization_id' => $organizationId];
        if (($filters['status'] ?? '') !== '') { $where[] = 'l.status = :status'; $params['status'] = (string) $filters['status']; }
        if (($filters['source'] ?? '') !== '') { $where[] = 'l.source_page = :source'; $params['source'] = (string) $filters['source']; }
        if (($filters['q'] ?? '') !== '') {
            $query = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(l.full_name LIKE :q_name OR l.email LIKE :q_email OR l.phone LIKE :q_phone)';
            $params['q_name'] = $query;
            $params['q_email'] = $query;
            $params['q_phone'] = $query;
        }
        $owner = (int) ($filters['owner_id'] ?? 0);
        if ($owner > 0) { $where[] = 'l.assigned_user_id = :owner_id'; $params['owner_id'] = $owner; }
        $limit = $this->limit($filters['limit'] ?? 100);
        $offset = $this->offset($filters['offset'] ?? 0);
        $orderBy = $this->leadOrder($filters);
        return $this->all(
            'SELECT l.id, l.full_name name, l.source_page source, l.status, l.assigned_user_id owner_id, u.full_name owner_name, '
            . 'l.created_at, l.last_contacted_at last_contact_at, l.next_contact_at next_action_at, '
            . 'CASE WHEN l.next_contact_at < NOW() THEN "HIGH" WHEN l.status = "new" THEN "MEDIUM" ELSE "NORMAL" END ai_priority, l.client_case_id deal_id '
            . 'FROM tn_leads l LEFT JOIN tn_users u ON u.id = l.assigned_user_id AND u.organization_id = l.organization_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
    }

    public function lead(string $organizationId, int $leadId): ?array
    {
        if ($leadId <= 0) return null;

        $lead = $this->one(
            'SELECT l.*, l.source_page source, l.assigned_user_id owner_id, u.full_name owner_name, '
            . 'l.last_contacted_at last_contact_at, l.next_contact_at next_action_at, l.client_case_id deal_id '
            . 'FROM tn_leads l LEFT JOIN tn_users u ON u.id = l.assigned_user_id AND u.organization_id = l.organization_id '
            . 'WHERE l.organization_id = :organization_id AND l.id = :lead_id LIMIT 1',
            ['organization_id' => $organizationId, 'lead_id' => $leadId],
        );
        if ($lead === null) return null;

        $lead['activities'] = $this->safeAll(
            'SELECT a.id,a.user_id,a.activity_type,a.title,a.body,a.due_at,a.completed_at,a.cancelled_at,a.cancellation_reason,a.created_at '
            . 'FROM tn_lead_activities a INNER JOIN tn_leads l ON l.id=a.lead_id '
            . 'WHERE l.organization_id=:organization_id AND a.lead_id=:lead_id ORDER BY a.created_at DESC LIMIT 100',
            ['organization_id' => $organizationId, 'lead_id' => $leadId],
        );

        $dealId = (int) ($lead['client_case_id'] ?? 0);
        $lead['opportunity'] = $dealId > 0 ? $this->deal($organizationId, $dealId) : null;
        $lead['communications'] = $dealId > 0 ? $this->safeAll(
            'SELECT id,channel,direction,sender,recipient,body,occurred_at '
            . 'FROM sales_communications WHERE organization_id=:organization_id AND deal_id=:deal_id '
            . 'ORDER BY occurred_at DESC LIMIT 50',
            ['organization_id' => $organizationId, 'deal_id' => $dealId],
        ) : [];

        return $lead;
    }

    public function deals(string $organizationId, array $filters = []): array
    {
        $where = ['c.organization_id = :organization_id'];
        $params = ['organization_id' => $organizationId];
        if (($filters['status'] ?? '') !== '') { $where[] = 'c.status = :status'; $params['status'] = (string) $filters['status']; }
        if (($filters['stage_id'] ?? '') !== '') { $where[] = 'c.stage_id = :stage_id'; $params['stage_id'] = (string) $filters['stage_id']; }
        if (($filters['pipeline_id'] ?? '') !== '') { $where[] = 'c.pipeline_id = :pipeline_id'; $params['pipeline_id'] = (string) $filters['pipeline_id']; }
        if (($filters['q'] ?? '') !== '') {
            $query = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(c.title LIKE :q_title OR c.public_id LIKE :q_public_id OR p.full_name LIKE :q_customer)';
            $params['q_title'] = $query;
            $params['q_public_id'] = $query;
            $params['q_customer'] = $query;
        }
        $owner = (int) ($filters['owner_id'] ?? 0);
        if ($owner > 0) { $where[] = 'c.assigned_user_id = :owner_id'; $params['owner_id'] = $owner; }
        if(!empty($filters['open_only']))$where[]='c.status IN ("active","paused")';
        if (($filters['risk'] ?? '') === 'high') $where[] = '(c.priority IN ("high", "urgent") OR c.next_contact_at < NOW() OR c.last_activity_at IS NULL OR c.last_activity_at < NOW() - INTERVAL 48 HOUR)';
        $limit = $this->limit($filters['limit'] ?? 100);
        $offset = $this->offset($filters['offset'] ?? 0);
        $orderBy = $this->dealOrder($filters);
        return $this->all(
            'SELECT c.id, c.public_id, c.title, c.status, c.priority, c.pipeline_id, c.stage_id, '
            . 'COALESCE(s.code, UPPER(c.stage)) stage_code, COALESCE(s.name, c.stage) stage_name, s.sort_order stage_order, '
            . 'p.full_name customer, c.assigned_user_id owner_id, u.full_name owner_name, '
            . 'c.deal_value deal_value, c.currency, COALESCE(c.probability, s.probability_default) probability, '
            . 'c.last_activity_at, c.next_contact_at next_action_at, c.expected_close_at, c.created_at, c.updated_at, '
            . 'CASE WHEN c.priority IN ("high", "urgent") OR c.next_contact_at < NOW() OR c.last_activity_at < NOW() - INTERVAL 48 HOUR THEN "HIGH" ELSE "NORMAL" END risk_level '
            . 'FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id '
            . 'LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id '
            . 'LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id = c.organization_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
    }

    public function deal(string $organizationId, int $dealId): ?array
    {
        return $this->one(
            'SELECT c.*, p.full_name customer, p.phone, p.email, p.telegram, u.full_name owner_name, '
            . 'pl.name pipeline_name, COALESCE(s.code, UPPER(c.stage)) stage_code, COALESCE(s.name, c.stage) stage_name, '
            . 'c.deal_value value, COALESCE(c.probability, s.probability_default) probability '
            . 'FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id '
            . 'LEFT JOIN tn_users u ON u.id = c.assigned_user_id AND u.organization_id = c.organization_id '
            . 'LEFT JOIN sales_pipelines pl ON pl.id = c.pipeline_id AND pl.organization_id = c.organization_id '
            . 'LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id = c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.id = :deal_id LIMIT 1',
            ['organization_id' => $organizationId, 'deal_id' => $dealId],
        );
    }

    public function timeline(string $organizationId, int $dealId, int $limit = 100): array
    {
        $limit = $this->limit($limit);

        $activities = array_map(
            static fn (array $row): array => [
                'id' => 'activity-' . $row['id'],
                'item_type' => 'ACTIVITY',
                'subtype' => $row['activity_type'],
                'title' => $row['title'],
                'detail' => $row['body'],
                'occurred_at' => $row['created_at'],
                'correlation_id' => null,
                'status' => $row['cancelled_at']!==null?'cancelled':($row['completed_at']!==null?'completed':'open'),
            ],
            $this->all(
                'SELECT id,activity_type,title,body,created_at,cancelled_at,completed_at FROM tn_client_case_activities '
                . 'WHERE organization_id=:organization_id AND client_case_id=:deal_id ORDER BY created_at DESC,id DESC LIMIT ' . $limit,
                ['organization_id' => $organizationId, 'deal_id' => $dealId],
            ),
        );

        $communications = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'item_type' => 'COMMUNICATION',
                'subtype' => $row['channel'],
                'title' => trim((string) $row['direction'] . ' · ' . (string) ($row['sender'] ?? '') . ' → ' . (string) ($row['recipient'] ?? '')),
                'detail' => $row['body'],
                'occurred_at' => $row['occurred_at'],
                'correlation_id' => null,
                'status' => $row['direction'],
            ],
            $this->all(
                'SELECT id,channel,direction,sender,recipient,body,occurred_at FROM sales_communications '
                . 'WHERE organization_id=:organization_id AND deal_id=:deal_id ORDER BY occurred_at DESC,id DESC LIMIT ' . $limit,
                ['organization_id' => $organizationId, 'deal_id' => $dealId],
            ),
        );

        $events = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'item_type' => 'EVENT',
                'subtype' => $row['type'],
                'title' => $row['type'],
                'detail' => $row['payload'],
                'occurred_at' => $row['occurred_at'],
                'correlation_id' => $row['correlation_id'],
                'status' => null,
            ],
            $this->all(
                'SELECT id,type,CAST(payload AS CHAR) payload,occurred_at,correlation_id FROM cos_events '
                . 'WHERE organization_id=:organization_id AND aggregate_type IN ("deal","client_case") AND aggregate_id=:deal_id ORDER BY occurred_at DESC,id DESC LIMIT ' . $limit,
                ['organization_id' => $organizationId, 'deal_id' => (string) $dealId],
            ),
        );

        $actions = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'item_type' => 'ACTION',
                'subtype' => $row['type'],
                'title' => $row['type'],
                'detail' => $row['parameters'],
                'occurred_at' => $row['created_at'],
                'correlation_id' => $row['correlation_id'],
                'status' => $row['status'],
            ],
            $this->all(
                'SELECT id,type,CAST(parameters AS CHAR) parameters,created_at,correlation_id,status FROM cos_actions '
                . 'WHERE organization_id=:organization_id AND target_type IN ("deal","client_case") AND target_id=:deal_id ORDER BY created_at DESC,id DESC LIMIT ' . $limit,
                ['organization_id' => $organizationId, 'deal_id' => (string) $dealId],
            ),
        );

        $timeline = array_merge($activities, $communications, $events, $actions);
        usort(
            $timeline,
            static fn (array $left, array $right): int => strcmp(
                (string) ($right['occurred_at'] ?? ''),
                (string) ($left['occurred_at'] ?? ''),
            ),
        );

        return array_slice($timeline, 0, $limit);
    }

    public function pipelines(string $organizationId): array
    {
        $pipelines = $this->all('SELECT * FROM sales_pipelines WHERE organization_id = :organization_id AND status = "ACTIVE" ORDER BY is_default DESC, name', ['organization_id' => $organizationId]);
        foreach ($pipelines as &$pipeline) {
            $pipeline['stages'] = $this->all(
                'SELECT * FROM sales_pipeline_stages WHERE organization_id = :organization_id AND pipeline_id = :pipeline_id ORDER BY sort_order, id',
                ['organization_id' => $organizationId, 'pipeline_id' => $pipeline['id']],
            );
        }
        return $pipelines;
    }

    public function today(string $organizationId, int $ownerId): array
    {
        return $this->todayForOwners($organizationId,$ownerId>0?[$ownerId]:null);
    }

    /** Null means authorized tenant population, [] means no records. */
    public function todayForOwners(string $organizationId,?array $owners):array
    {
        if ($owners!==null) {
            if (!array_is_list($owners)||count($owners)>1000) throw new \DomainException('Invalid Sales Today population.');
            foreach ($owners as $id) if (!is_int($id)||$id<1) throw new \DomainException('Invalid Sales Today owner.');
        }
        $population=$owners===null?'':($owners===[]?' AND 1=0':' AND %s.assigned_user_id IN ('.implode(',',$owners).')');
        $ownerSql=$population===''?'':sprintf($population,'c');
        $base=['organization_id'=>$organizationId];
        $query = fn (string $condition): array => $this->all(
            'SELECT c.id, c.public_id, c.title, p.full_name customer, c.priority, c.next_contact_at, c.last_activity_at '
            . 'FROM tn_client_cases c INNER JOIN tn_people p ON p.id = c.person_id AND p.organization_id = c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.status = "active"' . $ownerSql . ' AND ' . $condition
            . ' ORDER BY c.next_contact_at IS NULL, c.next_contact_at, c.updated_at DESC LIMIT 20',
            $base,
        );
        $activityOwnerSql=$ownerSql;
        $activityBase=['activity_org'=>$organizationId];
        $activities = fn (string $type, string $condition): array => $this->all(
            'SELECT c.id, c.public_id, c.title, p.full_name customer, c.priority, a.id activity_id, a.title activity_title, a.due_at '
            . 'FROM tn_client_case_activities a INNER JOIN tn_client_cases c ON c.id=a.client_case_id AND c.organization_id=a.organization_id '
            . 'INNER JOIN tn_people p ON p.id=c.person_id AND p.organization_id=c.organization_id '
            . 'WHERE a.organization_id=:activity_org AND a.activity_type="' . $type . '" AND a.completed_at IS NULL AND a.cancelled_at IS NULL' . $activityOwnerSql . ' AND ' . $condition
            . ' ORDER BY a.due_at IS NULL,a.due_at,c.updated_at DESC LIMIT 20',
            $activityBase,
        );
        $communicationOwnerSql=$population===''?'':sprintf($population,'d');
        $communicationParams=['communication_org'=>$organizationId];

        return [
            'must_do' => $query('c.next_contact_at BETWEEN CURRENT_DATE() AND CURRENT_DATE() + INTERVAL 1 DAY'),
            'overdue' => $query('c.next_contact_at < NOW()'),
            'waiting_for_client' => $query('c.next_contact_at IS NULL AND c.last_activity_at >= NOW() - INTERVAL 7 DAY'),
            'new_replies' => $this->safeAll(
                'SELECT d.id,d.public_id,d.title,p.full_name customer,d.priority,c.occurred_at,c.channel,c.body detail '
                . 'FROM sales_communications c INNER JOIN tn_client_cases d ON d.id=c.deal_id AND d.organization_id=c.organization_id '
                . 'INNER JOIN tn_people p ON p.id=d.person_id AND p.organization_id=d.organization_id '
                . 'WHERE c.organization_id=:communication_org AND c.direction="INBOUND" AND c.occurred_at>=NOW()-INTERVAL 24 HOUR' . $communicationOwnerSql
                . ' ORDER BY c.occurred_at DESC LIMIT 20',
                $communicationParams,
            ),
            'meetings' => $activities('meeting', 'a.due_at BETWEEN CURRENT_DATE() AND CURRENT_DATE()+INTERVAL 1 DAY'),
            'followups' => $activities('followup', 'a.due_at <= CURRENT_DATE()+INTERVAL 1 DAY'),
            'ai_recommended' => $owners!==null ? [] : $this->safeAll(
                'SELECT a.id action_id, a.type, a.target_id deal_id, a.status, a.risk_level, a.parameters, d.reason, d.confidence '
                . 'FROM cos_actions a LEFT JOIN cos_decisions d ON d.source_id = a.source_id AND d.organization_id=a.organization_id '
                . 'WHERE a.organization_id = :organization_id AND a.type LIKE "sales.%" AND a.target_type IN ("deal", "client_case") '
                . 'AND a.status IN ("PROPOSED", "PENDING_APPROVAL", "QUEUED") ORDER BY a.created_at DESC LIMIT 20',
                ['organization_id' => $organizationId],
            ),
        ];
    }

    public function metrics(string $organizationId, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $params = ['organization_id' => $organizationId];
        $core = $this->one(
            'SELECT COUNT(*) total_deals, SUM(c.status = "lost") lost_deals, '
            . 'ROUND(100 * SUM(COALESCE(s.is_won, 0) = 1) / NULLIF(COUNT(*), 0), 2) won_rate, '
            . 'ROUND(AVG(CASE WHEN c.closed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, c.created_at, c.closed_at) END), 2) sales_cycle_hours '
            . 'FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id = c.stage_id AND s.organization_id=c.organization_id '
            . 'WHERE c.organization_id = :organization_id AND c.created_at >= NOW() - INTERVAL ' . $days . ' DAY',
            $params,
        ) ?? [];
        $followups = $this->one(
            'SELECT ROUND(100 * SUM(a.completed_at IS NOT NULL) / NULLIF(COUNT(*),0),2) followup_completion_rate '
            . 'FROM tn_client_case_activities a INNER JOIN tn_client_cases c ON c.id=a.client_case_id AND c.organization_id=a.organization_id '
            . 'WHERE a.organization_id=:organization_id AND a.activity_type IN ("task","followup") AND a.cancelled_at IS NULL AND a.created_at>=NOW()-INTERVAL ' . $days . ' DAY',
            $params,
        ) ?? ['followup_completion_rate' => 0];
        $ai = $this->safeOne(
            'SELECT '
            . '(SELECT COUNT(*) FROM cos_actions ca WHERE ca.organization_id = :actions_org AND ca.created_at >= NOW() - INTERVAL ' . $days . ' DAY) actions_proposed, '
            . '(SELECT COUNT(*) FROM cos_actions ca WHERE ca.organization_id = :executed_org AND ca.status = "COMPLETED" AND ca.created_at >= NOW() - INTERVAL ' . $days . ' DAY) actions_executed, '
            . '(SELECT COUNT(*) FROM cos_action_outcomes o WHERE o.organization_id = :outcomes_org AND o.measured_at >= NOW() - INTERVAL ' . $days . ' DAY) actions_successful',
            ['actions_org' => $organizationId, 'executed_org' => $organizationId, 'outcomes_org' => $organizationId],
        ) ?? ['actions_proposed' => 0, 'actions_executed' => 0, 'actions_successful' => 0];
        return $core + $followups + $ai;
    }

    private function limit(mixed $value): int { return max(1, min((int) $value, 250)); }

    private function offset(mixed $value): int { return max(0, min((int) $value, 100000)); }

    private function leadOrder(array $filters): string
    {
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        return match ((string) ($filters['sort'] ?? 'created_at')) {
            'name' => 'l.full_name ' . $direction . ', l.id DESC',
            'status' => 'l.status ' . $direction . ', l.created_at DESC',
            'next_action' => 'l.next_contact_at IS NULL, l.next_contact_at ' . $direction . ', l.created_at DESC',
            'owner' => 'u.full_name IS NULL, u.full_name ' . $direction . ', l.created_at DESC',
            default => 'l.created_at ' . $direction . ', l.id DESC',
        };
    }

    private function dealOrder(array $filters): string
    {
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        return match ((string) ($filters['sort'] ?? 'stage')) {
            'created_at' => 'c.created_at ' . $direction . ', c.id DESC',
            'updated_at' => 'c.updated_at ' . $direction . ', c.id DESC',
            'value' => 'COALESCE(c.deal_value,c.budget_max,0) ' . $direction . ', c.updated_at DESC',
            'next_action' => 'c.next_contact_at IS NULL, c.next_contact_at ' . $direction . ', c.updated_at DESC',
            default => 'COALESCE(s.sort_order,0) ' . $direction . ', c.updated_at DESC',
        };
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function safeAll(string $sql, array $params = []): array
    {
        try { return $this->all($sql, $params); } catch (\PDOException) { return []; }
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function safeOne(string $sql, array $params = []): ?array
    {
        try { return $this->one($sql, $params); } catch (\PDOException) { return null; }
    }
}
