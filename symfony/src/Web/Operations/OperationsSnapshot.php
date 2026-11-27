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

    public function read(TenantContext $actor, ?EntityRef $entity, string $correlation, string $source, string $selected, array $cursor = [], ?OperationStatus $status = null): array
    {
        self::authorize($actor);
        $org = $actor->organizationId()->value();
        $rows = []; $unavailable = []; $detail = null; $detailUnavailable = false; $next = []; $more = false;
        foreach (self::SOURCES as $kind) {
            if ($source !== '' && $source !== $kind) continue;
            if (array_key_exists($kind, $cursor) && $cursor[$kind] === null) {
                $next[$kind] = null;
                continue;
            }
            try {
                $raw = $this->canonical->rows($org, $kind, 51, null, $cursor[$kind] ?? null, $correlation, $entity, $status);
                $page = array_slice($raw, 0, 50);
                foreach ($page as $record) {
                    $projected = in_array($kind, ['agent','async'], true)
                        ? $this->source($org, $kind, (string)$record['id'])
                        : [$this->canonicalRow($org, $kind, $record)];
                    foreach ($projected as $row) {
                        if (self::matches($row, $entity, $correlation) && ($status === null || $row['status'] === $status)) $rows[$row['key']] = $row;
                    }
                }
                $last = end($page);
                $next[$kind] = count($raw) > 50
                    ? ['date'=>(string)$last['created_at'], 'id'=>(string)$last['id']] : null;
                $more = $more || $next[$kind] !== null;
            } catch (Throwable $e) {
                $unavailable[] = $kind;
                // Preserve an unavailable source's cursor instead of silently losing its history.
                if (isset($cursor[$kind])) $next[$kind] = $cursor[$kind];
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
                        $detail = $candidate;
                        $detail['items'] = [];
                        $detail['items'] = $this->items($org, $kind, $id);
                        // A direct detail does not change the archive window or its cursor.
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
            'detailUnavailable'=>$detailUnavailable, 'updated'=>new \DateTimeImmutable(),
            'nextCursor'=>$more ? OperationsHistoryCursor::encode($next) : ''];
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
        return ($entity === null || $row['entity']?->key() === $entity->key()
                || ($entity->type === 'goal' && ($row['goal'] ?? null) === $entity->id))
            && ($correlation === '' || $row['correlation'] === $correlation);
    }

    private function source(string $org, string $kind, string $id): array
    {
        if ($kind === 'agent') {
            $values = array_filter([$this->agents->find($org, $id)]);
            $rows = [];
            foreach ($values as $run) {
                if ($run->organizationId !== $org || $run->id !== $id) continue;
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
            $values = array_filter([$this->async->find($org, $id)]);
            $rows = [];
            foreach ($values as $job) {
                if ($job->organizationId !== $org || $job->id !== $id) continue;
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
        return array_map(fn(array $raw): array => $this->canonicalRow($org, $kind, $raw),
            $this->canonical->rows($org, $kind, 50, $id));
    }

    private function canonicalRow(string $org, string $kind, array $raw): array
    {
        if ($kind === 'workflow' && $raw['native_status'] === 'completed' && $raw['title'] === 'federation.approved-plan') {
            try {
                $proof = $this->canonical->workflowProof($org, (string)$raw['id']);
                $raw['verified'] = ($proof['status'] ?? '') === 'verified'
                    && (int)($proof['total_steps'] ?? 0) > 0
                    && (int)$proof['verified_steps'] === (int)$proof['total_steps'];
            } catch (Throwable) {
                $raw['verified'] = false;
            }
        }
        return $this->row($kind, $raw);
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
            'actionId'=>$raw['action_id'] ?? null, 'approvalId'=>$raw['approval_id'] ?? null, 'linkedRun'=>$raw['linked_run'] ?? null,
            'approver'=>trim(($raw['approver_type'] ?? '').' '.($raw['approver_id'] ?? '')),
            'expires'=>$raw['expires_at'] ?? null, 'decided'=>$raw['decided_at'] ?? null,
            'decidedBy'=>trim(($raw['decided_by_type'] ?? '').' '.($raw['decided_by_id'] ?? '')),
            'retryScheduled'=>$raw['retry_scheduled'] ?? false, 'canRetry'=>$raw['can_retry'] ?? false,
            'hasError'=>(bool)($raw['has_error'] ?? false),
        ];
    }

    private function items(string $org, string $kind, string $id): array
    {
        if ($kind === 'workflow') {
            $steps = $this->workflows->steps(new WorkflowRunIdentity($org, $id));
            $graph = $this->canonical->workflowGraph($org, $id);
            $states = [];
            foreach ($steps as $step) $states[$step->stepId] = $step->state;
            if (count($graph) !== count($states) || array_diff_key($graph, $states) !== []) {
                throw new \DomainException('Pinned graph and execution steps differ.');
            }
            return array_map(static fn($s): array => [
                'title'=>$s->stepId.' · '.$s->capabilityId, 'native'=>$s->state,
                'receipt'=>$s->resultReference, 'attempt'=>$s->attempts, 'revision'=>$s->revision,
                'dependencies'=>$graph[$s->stepId],
                'blockers'=>array_values(array_filter($graph[$s->stepId],
                    static fn(string $dependency): bool => ($states[$dependency] ?? '') !== 'completed')),
                'effect'=>$s->sideEffectLevel,
            ], $steps);
        }
        if ($kind === 'action') {
            return array_map(static fn(array $r): array => [
                'title'=>'Attempt '.$r['attempt'], 'native'=>$r['status'], 'receipt'=>(string)$r['id'],
                'started'=>$r['started_at'], 'finished'=>$r['finished_at'], 'hasError'=>(bool)$r['has_error'],
            ], $this->canonical->actionAttempts($org,$id));
        }
        if ($kind === 'agent') {
            return array_values(array_map(static fn($a): array => [
                'title'=>$a->type,'native'=>$a->status->value,'receipt'=>null,'operation'=>'action:'.$a->id,
            ], array_filter($this->agents->actionsForRun($org,$id),
                static fn($a): bool=>$a->organizationId === $org && $a->runId === $id)));
        }
        if ($kind === 'import') {
            return array_map(static fn(array $r): array => ['title'=>($r['row_no'].' · '.($r['sku'] ?? '')), 'native'=>$r['status'],
                'receipt'=>$r['receipt_key'], 'error'=>$r['error_code'], 'product'=>$r['product_id']], $this->canonical->importItems($org, $id));
        }
        return [];
    }
}
