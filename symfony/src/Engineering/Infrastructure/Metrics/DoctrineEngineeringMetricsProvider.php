<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Metrics;

use App\Engineering\Application\Metrics\EngineeringMetricsProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringMetricsProvider implements EngineeringMetricsProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function summary(string $organizationId): array
    {
        $db = $this->entityManager->getConnection();
        $params = ['organization_id' => $organizationId];

        $featuresStarted = (int) $db->fetchOne('SELECT COUNT(*) FROM cos_engineering_features WHERE organization_id=:organization_id', $params);
        $featuresCompleted = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_features WHERE organization_id=:organization_id AND status='DONE'", $params);
        $failedWorkflows = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_workflows w JOIN cos_engineering_features f ON f.id=w.feature_id WHERE f.organization_id=:organization_id AND w.status='FAILED'", $params);
        $featuresEscalated = (int) $db->fetchOne("SELECT COUNT(DISTINCT t.feature_id) FROM cos_engineering_transitions t JOIN cos_engineering_features f ON f.id=t.feature_id WHERE f.organization_id=:organization_id AND t.to_state='ESCALATED'", $params);
        $humanInterventions = (int) $db->fetchOne('SELECT COUNT(*) FROM cos_engineering_human_decisions d JOIN cos_engineering_human_decision_requests r ON r.id=d.request_id JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id', $params);

        $agentRuns = (int) $db->fetchOne('SELECT COUNT(*) FROM cos_engineering_agent_runs r JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id', $params);
        $reviewRuns = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs r JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id AND r.agent_role='REVIEWER'", $params);
        $qaRuns = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs r JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id AND r.agent_role='QA'", $params);

        $managerTotal = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs r JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id AND r.agent_role='ENGINEERING_MANAGER' AND r.status <> 'RUNNING'", $params);
        $managerCompleted = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs r JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id AND r.agent_role='ENGINEERING_MANAGER' AND r.status='COMPLETED'", $params);

        $totalCost = (float) ($db->fetchOne('SELECT COALESCE(SUM(r.estimated_cost),0) FROM cos_engineering_agent_runs r JOIN cos_engineering_features f ON f.id=r.feature_id WHERE f.organization_id=:organization_id', $params) ?: 0);
        $avgTimeToReady = $db->fetchOne("
            SELECT AVG(TIMESTAMPDIFF(SECOND, w.started_at, t.created_at))
            FROM cos_engineering_workflows w
            JOIN cos_engineering_transitions t ON t.workflow_execution_id = w.id
            JOIN cos_engineering_features f ON f.id=w.feature_id
            WHERE f.organization_id=:organization_id AND t.to_state='READY_FOR_HUMAN_APPROVAL'
        ", $params);

        return [
            'features_started' => $featuresStarted,
            'features_completed' => $featuresCompleted,
            'features_escalated' => $featuresEscalated,
            'manager_analysis_success_rate' => $managerTotal > 0 ? round($managerCompleted / $managerTotal, 4) : null,
            'average_agent_runs_per_feature' => $featuresStarted > 0 ? round($agentRuns / $featuresStarted, 2) : 0.0,
            'average_review_cycles' => $featuresStarted > 0 ? round($reviewRuns / $featuresStarted, 2) : 0.0,
            'average_qa_cycles' => $featuresStarted > 0 ? round($qaRuns / $featuresStarted, 2) : 0.0,
            'human_interventions' => $humanInterventions,
            'cost_per_feature' => $featuresStarted > 0 ? round($totalCost / $featuresStarted, 6) : 0.0,
            'time_to_ready_seconds' => $avgTimeToReady !== false && $avgTimeToReady !== null ? round((float) $avgTimeToReady, 2) : null,
            'failed_workflows' => $failedWorkflows,
        ];
    }
}
