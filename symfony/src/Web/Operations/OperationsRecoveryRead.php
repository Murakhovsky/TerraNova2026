<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Persistence\Workflow\DoctrineKernelExecutionRecoveryInspector;
use App\Persistence\Workflow\DoctrineKernelExecutionRecoveryManager;
use App\Persistence\Workflow\DoctrineWorkflowRecoveryManager;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Same canonical diagnostics as the Workflow recovery API, never calls a writer. */
final readonly class OperationsRecoveryRead
{
    public function __construct(
        private DoctrineWorkflowRecoveryManager $workflows,
        private DoctrineKernelExecutionRecoveryManager $kernel,
        private DoctrineKernelExecutionRecoveryInspector $kernelInspector,
    ) {}

    public function inspect(TenantContext $actor, array $row): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS)
            || !$actor->allows(TenantPermissions::MANAGE) || $row['source'] !== 'workflow') {
            throw new \DomainException('Recovery diagnosis requires tenant manager.');
        }
        $org = $actor->organizationId()->value();
        $identity = new WorkflowRunIdentity($org, $row['id']);
        $command = new WorkflowRunCommand($identity, $actor, (int)$row['revision'], $row['correlation']);
        if ($this->kernel->owns($identity)) {
            $report = $this->kernelInspector->inspectTenant($org, 1, $row['id']);
            $item = $report['runs'][0] ?? null;
            if (!is_array($item) || $item['run_id'] !== $row['id'] || (int)$item['run_revision'] !== (int)$row['revision']) {
                throw new \DomainException('Recovery snapshot is stale.');
            }
            $disposition = $item['disposition']; $reason = $item['reason'];
            $unknown = $disposition !== 'verified';
        } else {
            $result = $this->workflows->inspect($command);
            $disposition = $result->disposition->value; $reason = $result->reason;
            $unknown = $result->externalOutcomeUnknown;
        }
        $requests = array_map(static fn(array $r): array => [
            'id'=>$r['request_id'], 'step'=>$r['step_id'], 'resolution'=>$r['resolution'],
            'status'=>$r['status'], 'requested'=>$r['requested_at'],
            'approved'=>$r['approved_at'], 'applied'=>$r['applied_at'],
        ], $this->workflows->requestsForRun($command,50));
        return ['disposition'=>$disposition, 'reason'=>$reason, 'unknown'=>$unknown, 'requests'=>$requests];
    }
}
