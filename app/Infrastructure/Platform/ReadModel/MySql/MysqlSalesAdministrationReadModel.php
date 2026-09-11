<?php
declare(strict_types=1);

namespace Infrastructure\Platform\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesAdministrationReadModelInterface;
use Domains\Sales\Application\Service\SalesAdministrationHealthClassifier;
use PDO;

final readonly class MysqlSalesAdministrationReadModel implements SalesAdministrationReadModelInterface
{
    public function __construct(private PDO $connection, private SalesAdministrationHealthClassifier $healthClassifier) {}

    public function dashboard(string $organizationId, int $limit = 50): array
    {
        $limit = max(5, min(100, $limit));
        $summary = $this->summary($organizationId);
        $classification = $this->healthClassifier->classify($summary);
        return [
            'generated_at'=>gmdate('c'),'status'=>$classification['status'],'subsystems'=>$classification['subsystems'],
            'issues'=>$classification['issues'],'summary'=>$summary,
            'integrations'=>$this->sanitizeRows($this->all(
                'SELECT id,integration_key,capability,provider,name,status,configuration_version,health_status,last_health_check_at,last_success_at,last_error,updated_at FROM cos_integrations WHERE organization_id=:organization_id ORDER BY status="ACTIVE" DESC,capability,provider,name LIMIT '.$limit,
                ['organization_id'=>$organizationId]
            ), ['last_error']),
            'runtime'=>[
                'failed_actions'=>$this->sanitizeRows($this->all(
                    'SELECT id,type,target_type,target_id,source_type,status,risk_level,correlation_id,last_error,failed_at,created_at FROM cos_actions WHERE organization_id=:organization_id AND status="FAILED" ORDER BY COALESCE(failed_at,updated_at) DESC LIMIT '.$limit,
                    ['organization_id'=>$organizationId]
                ), ['last_error']),
                'problem_jobs'=>$this->sanitizeRows($this->all(
                    'SELECT id,type,status,attempts,max_attempts,timeout_seconds,available_at,locked_at,last_error,correlation_id,created_at FROM cos_jobs WHERE organization_id=:organization_id AND status IN ("FAILED","DEAD","RUNNING") ORDER BY FIELD(status,"DEAD","FAILED","RUNNING"),created_at DESC LIMIT '.$limit,
                    ['organization_id'=>$organizationId]
                ), ['last_error']),
                'pending_approvals'=>$this->sanitizeRows($this->all(
                    'SELECT ap.id,ap.status,ap.approver_type,ap.approver_id,ap.reason,ap.expires_at,ap.created_at,a.type AS action_type,a.target_type,a.target_id,a.risk_level,a.correlation_id FROM cos_approvals ap INNER JOIN cos_actions a ON a.id=ap.action_id WHERE ap.organization_id=:organization_id AND ap.status="PENDING" ORDER BY (ap.expires_at IS NOT NULL AND ap.expires_at<NOW()) DESC,ap.created_at LIMIT '.$limit,
                    ['organization_id'=>$organizationId]
                ), ['reason']),
                'agent_failures'=>$this->sanitizeRows($this->all(
                    'SELECT id,agent_name,model,status,subject_type,subject_id,confidence,duration_ms,error,correlation_id,created_at FROM cos_agent_runs WHERE organization_id=:organization_id AND status IN ("FAILED","INVALID_OUTPUT") ORDER BY created_at DESC LIMIT '.$limit,
                    ['organization_id'=>$organizationId]
                ), ['error']),
                'crm_failures'=>$this->sanitizeRows($this->all(
                    'SELECT id,provider,external_event_id,event_type,status,attempts,last_error,correlation_id,created_at FROM cos_crm_inbox WHERE organization_id=:organization_id AND status IN ("FAILED","DEAD") ORDER BY FIELD(status,"DEAD","FAILED"),created_at DESC LIMIT '.$limit,
                    ['organization_id'=>$organizationId]
                ), ['last_error']),
            ],
            'audit'=>$this->sanitizeRows($this->all(
                'SELECT id,category,actor_type,actor_id,subject_type,subject_id,action,reason,correlation_id,created_at FROM cos_audit_log WHERE organization_id=:organization_id ORDER BY created_at DESC LIMIT '.$limit,
                ['organization_id'=>$organizationId]
            ), ['reason']),
            'configuration_revisions'=>$this->sanitizeRows($this->all(
                'SELECT id,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,created_at FROM cos_configuration_revisions WHERE organization_id=:organization_id AND domain_name="sales" ORDER BY created_at DESC,id DESC LIMIT '.$limit,
                ['organization_id'=>$organizationId]
            ), ['reason']),
            'metrics'=>$this->all(
                'SELECT metric,value,recorded_at FROM cos_operational_metrics WHERE organization_id=:organization_id OR organization_id IS NULL ORDER BY recorded_at DESC,id DESC LIMIT '.$limit,
                ['organization_id'=>$organizationId]
            ),
        ];
    }

    private function summary(string $organizationId): array
    {
        $row = $this->one(
            'SELECT '
            .'(SELECT COUNT(*) FROM cos_integrations WHERE organization_id=:i_active AND status="ACTIVE") AS active_integrations,'
            .'(SELECT COUNT(*) FROM cos_integrations WHERE organization_id=:i_error AND status="ACTIVE" AND health_status="ERROR") AS integration_errors,'
            .'(SELECT COUNT(*) FROM cos_integrations WHERE organization_id=:i_degraded AND status="ACTIVE" AND health_status="DEGRADED") AS integration_degraded,'
            .'(SELECT COUNT(*) FROM cos_integrations WHERE organization_id=:i_unknown AND status="ACTIVE" AND (health_status="UNKNOWN" OR (health_status="HEALTHY" AND (last_health_check_at IS NULL OR last_health_check_at<NOW()-INTERVAL 24 HOUR)))) AS integration_unknown,'
            .'(SELECT COUNT(*) FROM cos_jobs WHERE organization_id=:j_dead AND status="DEAD") AS dead_jobs,'
            .'(SELECT COUNT(*) FROM cos_jobs WHERE organization_id=:j_failed AND status="FAILED") AS failed_jobs,'
            .'(SELECT COUNT(*) FROM cos_jobs WHERE organization_id=:j_stalled AND status="RUNNING" AND locked_at IS NOT NULL AND TIMESTAMPDIFF(SECOND,locked_at,NOW())>timeout_seconds) AS stalled_jobs,'
            .'(SELECT COUNT(*) FROM cos_actions WHERE organization_id=:a_failed AND status="FAILED" AND COALESCE(failed_at,updated_at)>=NOW()-INTERVAL 24 HOUR) AS failed_actions_24h,'
            .'(SELECT COUNT(*) FROM cos_action_attempts WHERE organization_id=:aa_failed AND status="FAILED" AND started_at>=NOW()-INTERVAL 24 HOUR) AS failed_action_attempts_24h,'
            .'(SELECT COUNT(*) FROM cos_approvals WHERE organization_id=:ap_pending AND status="PENDING") AS pending_approvals,'
            .'(SELECT COUNT(*) FROM cos_approvals WHERE organization_id=:ap_overdue AND status="PENDING" AND expires_at IS NOT NULL AND expires_at<NOW()) AS overdue_approvals,'
            .'(SELECT COUNT(*) FROM cos_agent_runs WHERE organization_id=:ar_failed AND status IN ("FAILED","INVALID_OUTPUT") AND created_at>=NOW()-INTERVAL 24 HOUR) AS agent_failures_24h,'
            .'(SELECT COUNT(*) FROM cos_crm_inbox WHERE organization_id=:crm_failed AND status="FAILED") AS failed_crm_inbox,'
            .'(SELECT COUNT(*) FROM cos_crm_inbox WHERE organization_id=:crm_dead AND status="DEAD") AS dead_crm_inbox',
            ['i_active'=>$organizationId,'i_error'=>$organizationId,'i_degraded'=>$organizationId,'i_unknown'=>$organizationId,'j_dead'=>$organizationId,'j_failed'=>$organizationId,'j_stalled'=>$organizationId,'a_failed'=>$organizationId,'aa_failed'=>$organizationId,'ap_pending'=>$organizationId,'ap_overdue'=>$organizationId,'ar_failed'=>$organizationId,'crm_failed'=>$organizationId,'crm_dead'=>$organizationId]
        ) ?? [];
        $result=[]; foreach ($row as $key=>$value) $result[(string)$key]=(int)$value; return $result;
    }

    private function sanitizeRows(array $rows, array $fields): array
    {
        foreach ($rows as &$row) foreach ($fields as $field) if (array_key_exists($field,$row)) $row[$field]=$this->sanitizeText($row[$field]);
        unset($row); return $rows;
    }

    private function sanitizeText(mixed $value): ?string
    {
        if (!is_string($value) || trim($value)==='') return null;
        $value=preg_replace('/(token|secret|password|key)\s*[=:]\s*\S+/i','$1=[redacted]',$value) ?? $value;
        return mb_substr(trim($value),0,500);
    }

    private function all(string $sql,array $params=[]): array { $s=$this->connection->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    private function one(string $sql,array $params=[]): ?array { $s=$this->connection->prepare($sql); $s->execute($params); $row=$s->fetch(PDO::FETCH_ASSOC); return $row===false?null:$row; }
}
