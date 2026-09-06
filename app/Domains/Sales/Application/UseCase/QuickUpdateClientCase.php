<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Model\SalesPriority;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class QuickUpdateClientCase
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
        private ?PipelineRepositoryInterface $pipelines = null,
        private ?ChangeDealStage $changeDealStage = null,
        private ?AssignDealOwner $assignDealOwner = null,
    ) {
    }

    public function execute(int $caseId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $case = $this->readModel->case($caseId);
        if (!$case) {
            return ClientCaseCommandResult::failure('not_found');
        }

        $status = $this->allowed(
            (string) ($input['status'] ?? $case['status']),
            ClientCaseStatus::values(),
            (string) $case['status'],
        );
        $targetStageId = isset($input['stage_id']) ? trim((string) $input['stage_id']) : null;
        if ($targetStageId === null && array_key_exists('stage', $input)) {
            $pipelineId = (string) ($case['pipeline_id'] ?? '');
            if($this->pipelines===null)return ClientCaseCommandResult::failure('stage_service_unavailable');
            $resolved = $this->pipelines->findStageByCode($this->organizationId, $pipelineId, $this->canonicalStageCode((string) $input['stage']));
            if ($resolved === null) return ClientCaseCommandResult::failure('invalid_stage');
            $targetStageId = $resolved->id;
        }
        $priority = $this->allowed(
            (string) ($input['priority'] ?? $case['priority']),
            SalesPriority::values(),
            (string) $case['priority'],
        );
        $managerId = array_key_exists('assigned_user_id', $input)
            ? $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'])
            : ($case['assigned_user_id'] ?? null);
        $nextContact = array_key_exists('next_contact_at', $input)
            ? $this->dateTime((string) ($input['next_contact_at'] ?? ''))
            : ($case['next_contact_at'] ?? null);
        $correlationId = bin2hex(random_bytes(16));

        $ok = $this->transactions->transactional(function () use ($case, $caseId, $status, $targetStageId, $priority, $managerId, $nextContact, $user, $correlationId): bool {
            if ($targetStageId !== null && $targetStageId !== (string) ($case['stage_id'] ?? '')) {
                if($this->changeDealStage===null)return false;
                $stageResult = $this->changeDealStage->execute(new ChangeDealStageCommand(
                    $this->organizationId, (string) $caseId, $targetStageId,
                    isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system', $correlationId,
                ));
                if (!$stageResult->successful) return false;
            }
            if ($managerId !== null && (int)$managerId !== (int)($case['assigned_user_id'] ?? 0)) {
                if ($this->assignDealOwner === null) return false;
                $assignment=$this->assignDealOwner->execute($this->organizationId,(string)$caseId,(int)$managerId,$correlationId,isset($user['id'])?'USER':'SYSTEM',isset($user['id'])?(string)$user['id']:'system');
                if(!$assignment->successful)return false;
            }
            $updated = $this->commands->quickUpdate($this->organizationId, $caseId, [
                'priority' => $priority,
                'next_contact_at' => $nextContact,
            ]);
            if (!$updated) {
                return false;
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => 'status_change',
                'title' => 'Кейс швидко оновлено',
                'body' => 'Оновлено етап, статус, пріоритет або відповідального менеджера.',
                'due_at' => null,
                'completed_at' => null,
            ]);
            $metadata = new EventMetadata(
                $correlationId,
                null,
                isset($user['id']) ? 'USER' : 'SYSTEM',
                isset($user['id']) ? (string) $user['id'] : 'system',
            );
            $this->events->publish(ClientCaseChanged::create(
                bin2hex(random_bytes(16)),
                $this->organizationId,
                (string) $caseId,
                [
                    'status' => ['from' => (string) $case['status'], 'to' => $status],
                    'priority' => ['from' => (string) $case['priority'], 'to' => $priority],
                ],
                $metadata,
            ));
            return true;
        });

        return $ok ? ClientCaseCommandResult::success('updated') : ClientCaseCommandResult::failure('not_found');
    }

    private function canonicalStageCode(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'new' => 'NEW', 'qualification', 'need_defined', 'qualified' => 'QUALIFIED', 'matching', 'proposal' => 'PROPOSAL',
            'viewing', 'meeting' => 'MEETING', 'negotiation' => 'NEGOTIATION', 'deal', 'aftercare', 'won' => 'WON',
            'lost' => 'LOST', default => strtoupper($legacy),
        };
    }

    private function allowed(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function dateTime(string $value): ?string
    {
        $timestamp = strtotime(trim($value));
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
