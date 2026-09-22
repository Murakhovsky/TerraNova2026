<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\ReadModel\MySql;

use Domains\Growth\Application\Contract\GrowthWorkspaceReadModelInterface;
use PDO;

final readonly class MysqlGrowthWorkspaceReadModel implements GrowthWorkspaceReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function overview(string $organizationId): array
    {
        $statusCounts=[];
        $statement=$this->connection->prepare(
            'SELECT status,COUNT(*) AS total
             FROM tn_growth_candidates
             WHERE organization_id=:organization_id
             GROUP BY status'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $statusCounts[(string)$row['status']]=(int)$row['total'];
        }

        $modeCounts=[];
        $statement=$this->connection->prepare(
            'SELECT growth_mode,COUNT(*) AS total
             FROM tn_growth_candidates
             WHERE organization_id=:organization_id
             GROUP BY growth_mode'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $modeCounts[(string)$row['growth_mode']]=(int)$row['total'];
        }

        $accounts=(int)$this->scalar(
            'SELECT COUNT(*) FROM tn_growth_accounts WHERE organization_id=:organization_id',
            ['organization_id'=>$organizationId],
        );
        $signals=(int)$this->scalar(
            'SELECT COUNT(*) FROM tn_growth_signals WHERE organization_id=:organization_id',
            ['organization_id'=>$organizationId],
        );
        $researchPending=(int)$this->scalar(
            "SELECT COUNT(*) FROM tn_growth_research_runs
             WHERE organization_id=:organization_id AND status='running'",
            ['organization_id'=>$organizationId],
        );
        $handoffPending=(int)$this->scalar(
            "SELECT COUNT(*) FROM tn_growth_handoff_attempts
             WHERE organization_id=:organization_id AND status='running'",
            ['organization_id'=>$organizationId],
        );

        return [
            'candidate_total'=>array_sum($statusCounts),
            'status_counts'=>$statusCounts,
            'mode_counts'=>$modeCounts,
            'account_total'=>$accounts,
            'signal_total'=>$signals,
            'research_running'=>$researchPending,
            'handoff_running'=>$handoffPending,
            'qualified_total'=>(int)($statusCounts['qualified']??0),
            'ready_total'=>(int)($statusCounts['ready_for_handoff']??0),
            'monitoring_total'=>(int)($statusCounts['monitoring']??0),
            'recent_candidates'=>$this->candidates($organizationId,[],8),
            'recent_accounts'=>$this->accounts($organizationId,[],8),
        ];
    }

    public function candidates(string $organizationId,array $filters=[],int $limit=100): array
    {
        $limit=max(1,min(200,$limit));
        $where=['c.organization_id=:organization_id'];
        $params=['organization_id'=>$organizationId];

        $status=$this->filter($filters,'status',40);
        if($status!==null){
            $where[]='c.status=:status';
            $params['status']=$status;
        }
        $mode=$this->filter($filters,'growth_mode',40);
        if($mode!==null){
            $where[]='c.growth_mode=:growth_mode';
            $params['growth_mode']=$mode;
        }
        $target=$this->filter($filters,'target_domain',80);
        if($target!==null){
            $where[]='c.target_domain=:target_domain';
            $params['target_domain']=$target;
        }
        $subjectType=$this->filter($filters,'subject_type',80);
        if($subjectType!==null){
            $where[]='c.subject_type=:subject_type';
            $params['subject_type']=$subjectType;
        }
        $subjectId=$this->filter($filters,'subject_id',191);
        if($subjectId!==null){
            $where[]='c.subject_id=:subject_id';
            $params['subject_id']=$subjectId;
        }
        $query=$this->filter($filters,'q',191);
        if($query!==null){
            $where[]='(c.candidate_id LIKE :q OR c.subject_id LIKE :q OR c.opportunity_type LIKE :q OR c.recommended_play LIKE :q)';
            $params['q']='%'.$this->like($query).'%';
        }

        $sql='SELECT c.candidate_id,c.opportunity_type,c.growth_mode,c.subject_type,c.subject_id,c.target_domain,
                    c.status,c.qualification_reason,c.expected_value,c.recommended_play,c.recommended_action,
                    c.created_at,c.updated_at,
                    COUNT(DISTINCT cs.signal_id) AS signal_count,
                    a.name AS account_name,a.canonical_domain AS account_domain,
                    re.outcome AS latest_evaluation_outcome,
                    re.evaluated_at AS latest_evaluation_at,
                    ha.status AS latest_handoff_status,
                    ha.target_reference_type,ha.target_reference_id
             FROM tn_growth_candidates c
             LEFT JOIN tn_growth_candidate_signals cs
               ON cs.organization_id=c.organization_id AND cs.candidate_id=c.candidate_id
             LEFT JOIN tn_growth_accounts a
               ON c.subject_type=\'account\' AND a.organization_id=c.organization_id AND a.account_id=c.subject_id
             LEFT JOIN tn_growth_candidate_evaluations re
               ON re.id=(
                   SELECT re2.id FROM tn_growth_candidate_evaluations re2
                   WHERE re2.organization_id=c.organization_id AND re2.candidate_id=c.candidate_id
                   ORDER BY re2.evaluated_at DESC,re2.id DESC LIMIT 1
               )
             LEFT JOIN tn_growth_handoff_attempts ha
               ON ha.id=(
                   SELECT ha2.id FROM tn_growth_handoff_attempts ha2
                   WHERE ha2.organization_id=c.organization_id AND ha2.candidate_id=c.candidate_id
                   ORDER BY ha2.started_at DESC,ha2.id DESC LIMIT 1
               )
             WHERE '.implode(' AND ',$where).'
             GROUP BY c.id,re.id,ha.id,a.id
             ORDER BY c.updated_at DESC,c.candidate_id DESC
             LIMIT '.$limit;

        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['signal_count']=(int)$row['signal_count'];
        unset($row);
        return $rows;
    }

    public function accounts(string $organizationId,array $filters=[],int $limit=100): array
    {
        $limit=max(1,min(200,$limit));
        $where=['a.organization_id=:organization_id'];
        $params=['organization_id'=>$organizationId];

        $query=$this->filter($filters,'q',191);
        if($query!==null){
            $where[]='(a.name LIKE :q OR a.canonical_domain LIKE :q OR a.account_id LIKE :q)';
            $params['q']='%'.$this->like($query).'%';
        }

        $sql='SELECT a.account_id,a.name,a.canonical_domain,a.created_at,a.updated_at,
                    COUNT(DISTINCT c.candidate_id) AS candidate_count,
                    COUNT(DISTINCT ac.contact_id) AS contact_count,
                    (
                        SELECT m.fit_score FROM tn_growth_account_icp_matches m
                        WHERE m.organization_id=a.organization_id AND m.account_id=a.account_id
                        ORDER BY m.scored_at DESC,m.id DESC LIMIT 1
                    ) AS latest_fit_score,
                    (
                        SELECT s.observed_at FROM tn_growth_account_snapshots s
                        WHERE s.organization_id=a.organization_id AND s.account_id=a.account_id
                        ORDER BY s.captured_at DESC,s.id DESC LIMIT 1
                    ) AS latest_observed_at,
                    (
                        SELECT b.coverage_score FROM tn_growth_buying_committee_assessments b
                        WHERE b.organization_id=a.organization_id AND b.account_id=a.account_id
                        ORDER BY b.assessed_at DESC,b.id DESC LIMIT 1
                    ) AS committee_coverage
             FROM tn_growth_accounts a
             LEFT JOIN tn_growth_candidates c
               ON c.organization_id=a.organization_id AND c.subject_type=\'account\' AND c.subject_id=a.account_id
             LEFT JOIN tn_growth_account_contacts ac
               ON ac.organization_id=a.organization_id AND ac.account_id=a.account_id
             WHERE '.implode(' AND ',$where).'
             GROUP BY a.id
             ORDER BY a.updated_at DESC,a.account_id DESC
             LIMIT '.$limit;

        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){
            $row['candidate_count']=(int)$row['candidate_count'];
            $row['contact_count']=(int)$row['contact_count'];
            $row['latest_fit_score']=$row['latest_fit_score']===null?null:(int)$row['latest_fit_score'];
            $row['committee_coverage']=$row['committee_coverage']===null?null:(int)$row['committee_coverage'];
        }
        unset($row);
        return $rows;
    }

    private function scalar(string $sql,array $params): string|int|false
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    /** @param array<string,mixed> $filters */
    private function filter(array $filters,string $key,int $limit): ?string
    {
        $value=$filters[$key]??null;
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        if($value==='')return null;
        return mb_substr($value,0,$limit);
    }

    private function like(string $value): string
    {
        return strtr($value,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']);
    }
}
