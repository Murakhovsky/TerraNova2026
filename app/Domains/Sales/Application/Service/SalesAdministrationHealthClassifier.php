<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

final class SalesAdministrationHealthClassifier
{
    /** @param array<string,mixed> $summary */
    public function classify(array $summary): array
    {
        $counts = static fn (string $key): int => max(0, (int) ($summary[$key] ?? 0));
        $critical = [];
        $warnings = [];

        $integrationErrors = $counts('integration_errors');
        $integrationDegraded = $counts('integration_degraded');
        $integrationUnknown = $counts('integration_unknown');
        $deadJobs = $counts('dead_jobs');
        $deadCrm = $counts('dead_crm_inbox');
        $failedJobs = $counts('failed_jobs');
        $stalledJobs = $counts('stalled_jobs');
        $failedActions = $counts('failed_actions_24h');
        $failedAttempts = $counts('failed_action_attempts_24h');
        $failedCrm = $counts('failed_crm_inbox');
        $overdueApprovals = $counts('overdue_approvals');
        $agentFailures = $counts('agent_failures_24h');

        if ($integrationErrors > 0) $critical[] = $this->issue('integration_error', 'Active integrations report errors.', $integrationErrors);
        if ($deadJobs > 0) $critical[] = $this->issue('dead_jobs', 'Dead background jobs require intervention.', $deadJobs);
        if ($deadCrm > 0) $critical[] = $this->issue('dead_crm_inbox', 'Dead CRM inbox events require intervention.', $deadCrm);

        if ($integrationDegraded > 0) $warnings[] = $this->issue('integration_degraded', 'Active integrations are degraded.', $integrationDegraded);
        if ($integrationUnknown > 0) $warnings[] = $this->issue('integration_unknown', 'Active integrations have unknown or stale health.', $integrationUnknown);
        if ($failedJobs > 0) $warnings[] = $this->issue('failed_jobs', 'Background jobs are failing.', $failedJobs);
        if ($stalledJobs > 0) $warnings[] = $this->issue('stalled_jobs', 'Background jobs appear stalled.', $stalledJobs);
        if ($failedActions + $failedAttempts > 0) $warnings[] = $this->issue('execution_failures', 'Action execution failures occurred in the last 24 hours.', $failedActions + $failedAttempts);
        if ($failedCrm > 0) $warnings[] = $this->issue('crm_inbox_failures', 'CRM inbox events are failing.', $failedCrm);
        if ($overdueApprovals > 0) $warnings[] = $this->issue('overdue_approvals', 'Pending approvals are overdue.', $overdueApprovals);
        if ($agentFailures > 0) $warnings[] = $this->issue('agent_failures', 'Agent runs failed or returned invalid output in the last 24 hours.', $agentFailures);

        $subsystems = [
            'integrations' => $integrationErrors > 0 ? 'ERROR' : (($integrationDegraded + $integrationUnknown) > 0 ? 'DEGRADED' : 'HEALTHY'),
            'execution' => ($deadJobs + $deadCrm) > 0 ? 'ERROR' : (($failedJobs + $stalledJobs + $failedActions + $failedAttempts + $failedCrm) > 0 ? 'DEGRADED' : 'HEALTHY'),
            'approvals' => $overdueApprovals > 0 ? 'DEGRADED' : 'HEALTHY',
            'agents' => $agentFailures > 0 ? 'DEGRADED' : 'HEALTHY',
        ];

        return [
            'status' => $critical !== [] ? 'ERROR' : ($warnings !== [] ? 'DEGRADED' : 'HEALTHY'),
            'subsystems' => $subsystems,
            'issues' => ['critical' => $critical, 'warnings' => $warnings],
        ];
    }

    /** @return array{code:string,message:string,count:int} */
    private function issue(string $code, string $message, int $count): array
    {
        return ['code' => $code, 'message' => $message, 'count' => $count];
    }
}
