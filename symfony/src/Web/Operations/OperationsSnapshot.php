<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Operations\Read\CanonicalOperationRead;
use App\Web\Operations\Read\OperationListReadInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Web\Experience\Model\EntityRef;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Throwable;

/** Read-only composition. Each source can fail independently without implying an empty queue. */
final readonly class OperationsSnapshot
{
    public const SOURCES = ['workflow', 'action', 'approval', 'agent', 'async', 'import'];

    public function __construct(
        #[Autowire(service: CanonicalOperationRead::class)]
        private OperationListReadInterface $canonical,
        private AgentRunReadModelInterface $agents,
        private AsyncOperationReadModelInterface $async,
        private WorkflowExecutionStoreInterface $workflows,
    ) {}

    public function read(TenantContext $actor, ?EntityRef $entity, string $correlation, string $source, string $selected): array
    {
        self::authorize($actor);
        $org = $actor->organizationId()->value();
        $rows = []; $unavailable = []; $detail = null; $detailUnavailable = false;
        foreach (self::SOURCES as $kind) {
            if ($source !== '' && $source !== $kind) continue;
            try {
                foreach ($this->source($org, $kind) as $row) {
                    if (self::matches($row, $entity, $correlation)) $rows[$row['key']] = $row;
                }
            } catch (Throwable $e) {
                $unavailable[] = $kind;
                error_log('cos.operations.snapshot.unavailable '.$kind.' '.$e::class);
            }
        }
        if ($selected !== '') {
            [$kind, $id] = array_pad(explode(':', $selected, 2), 2, '');
            if (in_array($kind, self::SOURCES, true) && $id !== '' && strlen($id) <= 190
                && ($source === '' || $source === $kind)) {
                try {
                    $candidate = $this->source($org, $kind, $id)[0] ?? null;
                    if ($candidate !== null && self::matches($candidate, $entity, $correlation)) {
                        $items = $this->items($org, $kind, $id);
                        $detail = $candidate;
                        $detail['items'] = $items;
                        $rows[$detail['key']] = $candidate;
                    }
                } catch (Throwable $e) {
                    $detailUnavailable = true;
                    error_log('cos.operations.detail.unavailable '.$kind.' '.$e::class);
                }
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn(array $a, array $b): int =>
            [$b['status']->needsAttention(), $b['updated'], $b['key']] <=> [$a['status']->needsAttention(), $a['updated'], $a['key']]);
        return ['rows'=>$rows, 'unavailable'=>$unavailable, 'detail'=>$detail,
            'detailUnavailable'=>$detailUnavailable, 'updated'=>new \DateTimeImmutable()];
    }

    public function one(TenantContext $actor, string $kind, string $id): ?array
    {
        self::authorize($actor);
        if (!in_array($kind, self::SOURCES, true) || $id === '' || strlen($id)>190) return null;
        return $this->source($actor->organizationId()->value(), $kind, $id)[0] ?? null;
    }

    private static function authorize(TenantContext $actor): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS) || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new \DomainException('Operations read requires tenant manager access.');
        }
    }

    private static function matches(array $row, ?EntityRef $entity, string $correlation): bool
    {
        return ($entity === null || $row['entity']?->key() === $entity->key())
            && ($correlation === '' || $row['correlation'] === $correlation);
    }

    private function source(string $org, string $kind, ?string $id = null): array
    {
        if ($kind === 'agent') {
            $values = $id === null ? $this->agents->recentForOrganization($org, 50) : array_filter([$this->agents->find($org, $id)]);
            $rows = [];
            foreach ($values as $run) {
                if ($run->organizationId !== $org) continue;
                $rows[] = $this->row($kind, [
                    'id'=>$run->id, 'title'=>$run->agentName, 'native_status'=>$run->status->value,
                    'target_type'=>$run->subjectType, 'target_id'=>$run->subjectId,
                    'created_at'=>$run->createdAt->format('c'), 'started_at'=>$run->startedAt?->format('c'),
                    'updated_at'=>($run->finishedAt ?? $run->startedAt ?? $run->createdAt)->format('c'),
                    'correlation_id'=>$run->correlationId, 'source_type'=>'Agent',
                    'has_error'=>$run->error !== null,
                ]);
            }
            return $rows;
        }
        if ($kind === 'async') {
            $values = $id === null ? $this->async->recentForOrganization($org, 50) : array_filter([$this->async->find($org, $id)]);
            $rows = [];
            foreach ($values as $job) {
                if ($job->organizationId !== $org) continue;
                $rows[] = $this->row($kind, [
                    'id'=>$job->id, 'title'=>$job->type, 'native_status'=>$job->status->value,
                    'target_type'=>$job->entityType, 'target_id'=>$job->entityId,
                    'source_id'=>$job->actorId, 'created_at'=>$job->createdAt->format('c'),
                    'started_at'=>$job->startedAt?->format('c'), 'updated_at'=>$job->updatedAt->format('c'),
                    'correlation_id'=>$job->correlationId, 'progress'=>$job->progress, 'attempt'=>$job->attempts,
                    'retry_scheduled'=>$job->retryScheduled, 'can_retry'=>$job->canRetry,
                    'has_error'=>$job->error !== null,
                ]);
            }
            return $rows;
        }
        $rows = [];
        foreach ($this->canonical->rows($org, $kind, 50, $id) as $raw) {
            if ($kind === 'workflow' && $raw['native_status'] === 'completed' && $raw['title'] === 'federation.approved-plan') {
                try {
                    $proof = $this->canonical->workflowProof($org, (string)$raw['id']);
                    $raw['verified'] = ($proof['status'] ?? '') === 'verified'
                        && (int)($proof['total_steps'] ?? 0) > 0
                        && $proof['verified_steps'] === $proof['total_steps'];
                } catch (Throwable) {
                    $raw['verified'] = false;
                }
            }
            $rows[] = $this->row($kind, $raw);
        }
        return $rows;
    }

    private function row(string $kind, array $raw): array
    {
        $entity = null;
        if (isset($raw['target_type'], $raw['target_id'])) {
            try { $entity = new EntityRef(strtolower((string)$raw['target_type']), (string)$raw['target_id']); }
            catch (\InvalidArgumentException) {}
        } elseif ($kind === 'import') $entity = new EntityRef('product_import_batch', (string)$raw['id']);
        $status = OperationStatus::fromNative($kind, (string)$raw['native_status'], $raw['verified'] ?? false, $raw['retry_scheduled'] ?? false);
        if ($kind === 'workflow' && (int)($raw['uncertain_steps'] ?? 0) > 0 && $status !== OperationStatus::Unknown) $status = OperationStatus::Reconciliation;
        if ($kind === 'approval' && $status === OperationStatus::WaitingApproval
            && (($raw['action_status'] ?? '') !== 'PENDING_APPROVAL'
                || (isset($raw['expires_at']) && strtotime($raw['expires_at'].' UTC') <= time()))) {
            $status = OperationStatus::Blocked;
        }
        $total = (int)($raw['total_steps'] ?? 0);
        $done = (int)($raw['completed_steps'] ?? 0);
        $progress = isset($raw['progress']) ? max(0, min(100, (int)$raw['progress'])) : null;
        if ($total > 0 && $done >= 0 && $done <= $total) $progress = (int)floor(100*$done/$total);
        return [
            'key'=>$kind.':'.$raw['id'], 'id'=>(string)$raw['id'], 'source'=>$kind,
            'title'=>ucfirst(str_replace(['.', '_', '-'], ' ', (string)$raw['title'])),
            'native'=>(string)$raw['native_status'], 'status'=>$status, 'entity'=>$entity,
            'created'=>$raw['created_at'] ?? null, 'started'=>$raw['started_at'] ?? null, 'updated'=>$raw['updated_at'] ?? '',
            'actor'=>trim(($raw['source_type'] ?? '').' '.($raw['source_id'] ?? '')),
            'correlation'=>$raw['correlation_id'] ?? '', 'goal'=>$raw['goal_id'] ?? null, 'plan'=>$raw['source_plan_id'] ?? null,
            'revision'=>$raw['revision'] ?? null, 'risk'=>$raw['risk_level'] ?? null,
            'progress'=>$progress, 'total'=>$total, 'done'=>$done, 'attempt'=>$raw['attempt'] ?? null,
            'receipt'=>$raw['receipt_id'] ?? null, 'receiptStatus'=>$raw['receipt_status'] ?? null,
            'actionId'=>$raw['action_id'] ?? null, 'approvalId'=>$raw['approval_id'] ?? null,
            'retryScheduled'=>$raw['retry_scheduled'] ?? false, 'canRetry'=>$raw['can_retry'] ?? false,
            'hasError'=>$raw['has_error'] ?? false,
        ];
    }

    private function items(string $org, string $kind, string $id): array
    {
        if ($kind === 'workflow') {
            return array_map(static fn($s): array => ['title'=>$s->capabilityId, 'native'=>$s->state, 'receipt'=>$s->resultReference],
                $this->workflows->steps(new WorkflowRunIdentity($org, $id)));
        }
        if ($kind === 'import') {
            return array_map(static fn(array $r): array => ['title'=>($r['row_no'].' · '.($r['sku'] ?? '')), 'native'=>$r['status'],
                'receipt'=>$r['product_id'], 'error'=>$r['error_code']], $this->canonical->importItems($org, $id));
        }
        return [];
    }
}
