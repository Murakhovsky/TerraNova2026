<?php
declare(strict_types=1);

namespace Infrastructure\Platform\ReadModel\MySql;

use Kernel\Operations\Contract\OperationsReadModelInterface;
use PDO;

final readonly class MysqlOperationsReadModel implements OperationsReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function overview(string $organizationId, int $limit = 30): array
    {
        $limit = max(5, min($limit, 100));
        $organization = ['organization_id' => $organizationId];
        return [
            'stats' => $this->one(
                'SELECT '
                . '(SELECT COUNT(*) FROM cos_events WHERE organization_id = :events_org) AS events, '
                . '(SELECT COUNT(*) FROM cos_decisions WHERE organization_id = :decisions_org) AS decisions, '
                . "(SELECT COUNT(*) FROM cos_actions WHERE organization_id = :actions_org AND status NOT IN ('COMPLETED', 'REJECTED')) AS open_actions, "
                . "(SELECT COUNT(*) FROM cos_approvals WHERE organization_id = :approvals_org AND status = 'PENDING') AS pending_approvals, "
                . "(SELECT COUNT(*) FROM cos_action_attempts WHERE organization_id = :results_org AND status = 'COMPLETED') AS completed_results, "
                . "(SELECT COUNT(*) FROM cos_jobs WHERE organization_id = :jobs_org AND status = 'DEAD') AS dead_jobs, "
                . "(SELECT COUNT(*) FROM cos_event_outbox WHERE organization_id = :outbox_org AND status IN ('FAILED', 'DEAD')) AS failed_events, "
                . "(SELECT COUNT(*) FROM cos_rules WHERE organization_id = :rules_org AND status = 'ACTIVE') AS active_rules, "
                . "(SELECT COUNT(DISTINCT agent_name) FROM cos_agent_runs WHERE organization_id = :agents_org) AS active_agents, "
                . "(SELECT COUNT(*) FROM cos_agent_runs WHERE organization_id = :runs_org AND created_at >= NOW() - INTERVAL 24 HOUR) AS agent_runs_24h, "
                . "(SELECT COUNT(*) FROM cos_events WHERE organization_id = :events24_org AND occurred_at >= NOW() - INTERVAL 24 HOUR) AS events_24h",
                [
                    'events_org' => $organizationId, 'decisions_org' => $organizationId,
                    'actions_org' => $organizationId, 'approvals_org' => $organizationId,
                    'results_org' => $organizationId, 'jobs_org' => $organizationId, 'outbox_org' => $organizationId,
                    'rules_org' => $organizationId, 'agents_org' => $organizationId, 'runs_org' => $organizationId,
                    'events24_org' => $organizationId,
                ],
            ) ?? [],
            'events' => $this->all(
                'SELECT id, type, aggregate_type, aggregate_id, payload, metadata, correlation_id, occurred_at '
                . 'FROM cos_events WHERE organization_id = :organization_id ORDER BY occurred_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'decisions' => $this->all(
                'SELECT d.*, ar.agent_name, ar.model, ar.prompt_version FROM cos_decisions d '
                . 'LEFT JOIN cos_agent_runs ar ON ar.id = d.source_id '
                . 'WHERE d.organization_id = :organization_id ORDER BY d.created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'agent_runs' => $this->all(
                'SELECT id, agent_name, agent_version, provider, model, prompt_version, schema_version, subject_type, subject_id, '
                . 'status, confidence, duration_ms, error, correlation_id, started_at, finished_at, created_at '
                . 'FROM cos_agent_runs WHERE organization_id = :organization_id ORDER BY created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'rules' => $this->all(
                'SELECT id, code, name, trigger_type, conditions, effect, priority, version, status, updated_at '
                . 'FROM cos_rules WHERE organization_id = :organization_id ORDER BY priority, name LIMIT ' . $limit,
                $organization,
            ),
            'policies' => $this->all(
                'SELECT id, code, name, action_type, conditions, decision, priority, version, status, updated_at '
                . 'FROM cos_policies WHERE organization_id = :organization_id ORDER BY action_type, priority LIMIT ' . $limit,
                $organization,
            ),
            'integrations' => $this->all(
                'SELECT id, integration_key, capability, provider, name, status, configuration_version, health_status, '
                . 'last_health_check_at, last_success_at, updated_at '
                . 'FROM cos_integrations WHERE organization_id = :organization_id ORDER BY capability, provider LIMIT ' . $limit,
                $organization,
            ),
            'actions' => $this->all(
                'SELECT a.*, ap.id AS approval_id, ap.status AS approval_status, ap.reason AS approval_reason '
                . 'FROM cos_actions a LEFT JOIN cos_approvals ap ON ap.action_id = a.id '
                . 'WHERE a.organization_id = :organization_id ORDER BY a.created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'approvals' => $this->all(
                'SELECT ap.*, a.type AS action_type, a.target_type, a.target_id, a.parameters, a.risk_level '
                . 'FROM cos_approvals ap INNER JOIN cos_actions a ON a.id = ap.action_id '
                . 'WHERE ap.organization_id = :organization_id ORDER BY ap.created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'results' => $this->all(
                'SELECT aa.*, a.type AS action_type, a.target_type, a.target_id, a.correlation_id '
                . 'FROM cos_action_attempts aa INNER JOIN cos_actions a ON a.id = aa.action_id '
                . "WHERE aa.organization_id = :organization_id AND aa.status IN ('COMPLETED', 'FAILED') "
                . 'ORDER BY aa.finished_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'audit' => $this->all(
                'SELECT * FROM cos_audit_log WHERE organization_id = :organization_id '
                . 'ORDER BY created_at DESC LIMIT ' . $limit,
                $organization,
            ),
        ];
    }

    public function dealIntelligence(string $organizationId, int $dealId): array
    {
        $parameters = ['organization_id' => $organizationId, 'target_id' => (string) $dealId];
        $actions = $this->all(
            'SELECT a.*, d.decision, d.reason AS decision_reason, d.confidence, d.evidence, '
            . 'ar.model, ar.prompt_version, ap.id AS approval_id, ap.status AS approval_status, '
            . 'attempt.status AS result_status, attempt.result, attempt.error AS result_error '
            . 'FROM cos_actions a '
            . "LEFT JOIN cos_decisions d ON d.source_type = 'AGENT' AND d.source_id = a.source_id "
            . 'LEFT JOIN cos_agent_runs ar ON ar.id = a.source_id '
            . 'LEFT JOIN cos_approvals ap ON ap.action_id = a.id '
            . 'LEFT JOIN cos_action_attempts attempt ON attempt.id = ('
            . 'SELECT ca.id FROM cos_action_attempts ca WHERE ca.action_id = a.id ORDER BY ca.attempt DESC LIMIT 1) '
            . "WHERE a.organization_id = :organization_id AND a.target_type IN ('deal', 'client_case') "
            . "AND a.target_id = :target_id AND a.source_type = 'AGENT' ORDER BY a.created_at DESC LIMIT 6",
            $parameters,
        );
        $decision = $this->one(
            'SELECT d.*, ar.agent_name, ar.model, ar.prompt_version FROM cos_decisions d '
            . 'LEFT JOIN cos_agent_runs ar ON ar.id = d.source_id '
            . "WHERE d.organization_id = :organization_id AND d.subject_type IN ('deal', 'client_case') "
            . 'AND d.subject_id = :target_id ORDER BY d.created_at DESC LIMIT 1',
            $parameters,
        );
        $outcomes = $this->all(
            'SELECT o.*, a.type AS action_type FROM cos_action_outcomes o INNER JOIN cos_actions a ON a.id = o.action_id '
            . 'WHERE o.organization_id = :organization_id AND a.target_type IN ("deal", "client_case") '
            . 'AND a.target_id = :target_id ORDER BY o.measured_at DESC LIMIT 20',
            $parameters,
        );
        return ['decision' => $decision, 'actions' => $actions, 'outcomes' => $outcomes];
    }

    public function health(): array
    {
        $database = (int) $this->connection->query('SELECT 1')->fetchColumn() === 1;
        $migrations = (int) $this->connection->query('SELECT COUNT(*) FROM tn_migrations')->fetchColumn();
        $deadJobs = (int) $this->connection->query("SELECT COUNT(*) FROM cos_jobs WHERE status = 'DEAD'")->fetchColumn();
        $deadEvents = (int) $this->connection->query("SELECT COUNT(*) FROM cos_event_outbox WHERE status = 'DEAD'")->fetchColumn();
        $deadCrm = (int) $this->connection->query("SELECT COUNT(*) FROM cos_crm_inbox WHERE status = 'DEAD'")->fetchColumn();
        $outboxLag = (int) $this->connection->query(
            "SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, created_at, NOW())), 0) FROM cos_event_outbox WHERE status IN ('PENDING', 'FAILED')"
        )->fetchColumn();
        $jobLag = (int) $this->connection->query(
            "SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, created_at, NOW())), 0) FROM cos_jobs WHERE status IN ('PENDING', 'FAILED')"
        )->fetchColumn();
        $healthy = $database && $deadJobs === 0 && $deadEvents === 0 && $deadCrm === 0
            && $outboxLag < 300 && $jobLag < 300;
        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'migrations' => $migrations,
            'dead_jobs' => $deadJobs,
            'dead_events' => $deadEvents,
            'dead_crm_inbox' => $deadCrm,
            'outbox_lag_seconds' => $outboxLag,
            'job_lag_seconds' => $jobLag,
        ];
    }

    private function all(string $sql, array $params = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
