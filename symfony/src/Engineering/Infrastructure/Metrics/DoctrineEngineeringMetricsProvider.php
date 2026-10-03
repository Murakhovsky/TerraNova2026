<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Metrics;

use App\Engineering\Application\Metrics\EngineeringMetricsProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEngineeringMetricsProvider implements EngineeringMetricsProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function summary(): array
    {
        $db = $this->entityManager->getConnection();

        $featuresStarted = (int) $db->fetchOne('SELECT COUNT(*) FROM cos_engineering_features');
        $featuresCompleted = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_features WHERE status='DONE'");
        $failedWorkflows = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_workflows WHERE status='FAILED'");
        $featuresEscalated = (int) $db->fetchOne("SELECT COUNT(DISTINCT feature_id) FROM cos_engineering_transitions WHERE to_state='ESCALATED'");
        $humanInterventions = (int) $db->fetchOne('SELECT COUNT(*) FROM cos_engineering_human_decisions');

        $agentRuns = (int) $db->fetchOne('SELECT COUNT(*) FROM cos_engineering_agent_runs');
        $reviewRuns = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs WHERE agent_role='REVIEWER'");
        $qaRuns = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs WHERE agent_role='QA'");

        $managerTotal = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs WHERE agent_role='ENGINEERING_MANAGER' AND status <> 'RUNNING'");
        $managerCompleted = (int) $db->fetchOne("SELECT COUNT(*) FROM cos_engineering_agent_runs WHERE agent_role='ENGINEERING_MANAGER' AND status='COMPLETED'");

        $totalCost = (float) ($db->fetchOne('SELECT COALESCE(SUM(estimated_cost),0) FROM cos_engineering_agent_runs') ?: 0);
        $avgTimeToReady = $db->fetchOne("
            SELECT AVG(TIMESTAMPDIFF(SECOND, w.started_at, t.created_at))
            FROM cos_engineering_workflows w
            JOIN cos_engineering_transitions t ON t.workflow_execution_id = w.id
            WHERE t.to_state='READY_FOR_HUMAN_APPROVAL'
        ");

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
