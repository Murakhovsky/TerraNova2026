<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;

final readonly class CosConsoleService
{
    public function __construct(
        private DatabaseService $database,
        private string $organizationId,
    ) {}

    /** @return array<string, mixed> */
    public function overview(int $limit = 30): array
    {
        $limit = max(5, min($limit, 100));
        $organization = ['organization_id' => $this->organizationId];

        return [
            'stats' => $this->database->fetchOne(
                'SELECT '
                . '(SELECT COUNT(*) FROM cos_events WHERE organization_id = :events_org) AS events, '
                . '(SELECT COUNT(*) FROM cos_decisions WHERE organization_id = :decisions_org) AS decisions, '
                . "(SELECT COUNT(*) FROM cos_actions WHERE organization_id = :actions_org AND status NOT IN ('COMPLETED', 'REJECTED')) AS open_actions, "
                . "(SELECT COUNT(*) FROM cos_approvals WHERE organization_id = :approvals_org AND status = 'PENDING') AS pending_approvals, "
                . "(SELECT COUNT(*) FROM cos_action_attempts WHERE organization_id = :results_org AND status = 'COMPLETED') AS completed_results, "
                . "(SELECT COUNT(*) FROM cos_jobs WHERE organization_id = :jobs_org AND status = 'DEAD') AS dead_jobs",
                [
                    'events_org' => $this->organizationId, 'decisions_org' => $this->organizationId,
                    'actions_org' => $this->organizationId, 'approvals_org' => $this->organizationId,
                    'results_org' => $this->organizationId, 'jobs_org' => $this->organizationId,
                ],
            ) ?? [],
            'events' => $this->database->fetchAll(
                'SELECT id, type, aggregate_type, aggregate_id, payload, metadata, correlation_id, occurred_at '
                . 'FROM cos_events WHERE organization_id = :organization_id ORDER BY occurred_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'decisions' => $this->database->fetchAll(
                'SELECT d.*, ar.agent_name, ar.model, ar.prompt_version '
                . 'FROM cos_decisions d LEFT JOIN cos_agent_runs ar ON ar.id = d.source_id '
                . 'WHERE d.organization_id = :organization_id ORDER BY d.created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'actions' => $this->database->fetchAll(
                'SELECT a.*, ap.id AS approval_id, ap.status AS approval_status, ap.reason AS approval_reason '
                . 'FROM cos_actions a LEFT JOIN cos_approvals ap ON ap.action_id = a.id '
                . 'WHERE a.organization_id = :organization_id ORDER BY a.created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'approvals' => $this->database->fetchAll(
                'SELECT ap.*, a.type AS action_type, a.target_type, a.target_id, a.parameters, a.risk_level '
                . 'FROM cos_approvals ap INNER JOIN cos_actions a ON a.id = ap.action_id '
                . 'WHERE ap.organization_id = :organization_id ORDER BY ap.created_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'results' => $this->database->fetchAll(
                'SELECT aa.*, a.type AS action_type, a.target_type, a.target_id, a.correlation_id '
                . 'FROM cos_action_attempts aa INNER JOIN cos_actions a ON a.id = aa.action_id '
                . "WHERE aa.organization_id = :organization_id AND aa.status IN ('COMPLETED', 'FAILED') "
                . 'ORDER BY aa.finished_at DESC LIMIT ' . $limit,
                $organization,
            ),
            'audit' => $this->database->fetchAll(
                'SELECT * FROM cos_audit_log WHERE organization_id = :organization_id '
                . 'ORDER BY created_at DESC LIMIT ' . $limit,
                $organization,
            ),
        ];
    }

    /** @return array{decision: ?array, actions: list<array>} */
    public function dealIntelligence(int $dealId): array
    {
        $parameters = ['organization_id' => $this->organizationId, 'target_id' => (string) $dealId];
        $actions = $this->database->fetchAll(
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
            . "AND a.target_id = :target_id AND a.source_type = 'AGENT' "
            . 'ORDER BY a.created_at DESC LIMIT 6',
            $parameters,
        );
        $decision = $this->database->fetchOne(
            "SELECT d.*, ar.agent_name, ar.model, ar.prompt_version FROM cos_decisions d "
            . 'LEFT JOIN cos_agent_runs ar ON ar.id = d.source_id '
            . "WHERE d.organization_id = :organization_id AND d.subject_type IN ('deal', 'client_case') "
            . 'AND d.subject_id = :target_id ORDER BY d.created_at DESC LIMIT 1',
            $parameters,
        );

        return ['decision' => $decision, 'actions' => $actions];
    }
}
