<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyProjectionInterface;
use Domains\Property\Application\Contract\PropertyManagementWorkflowRepositoryInterface;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;
use Domains\Property\Model\PropertyWorkflowPolicy;
use Throwable;

final readonly class CanonicalPropertyManagementWorkflowRepository implements PropertyManagementWorkflowRepositoryInterface
{
    private PropertyWorkflowPolicy $workflow;

    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private MysqlPropertyManagementRepository $legacyOperations,
        private PropertyProjectionInterface $projection,
        private string $organizationId,
        ?PropertyWorkflowPolicy $workflow = null,
    ) {
        $this->workflow = $workflow ?? new PropertyWorkflowPolicy();
    }

    public function operationalStageRules(): array { return $this->workflow->stageRules(); }
    public function operationalStageCheck(int $propertyId): array { return $this->legacyOperations->operationalStageCheck($propertyId); }

    public function quickAction(int $propertyId, string $action, array $input = [], ?int $userId = null): array
    {
        $action = trim($action);
        try {
            if ($action === 'publish') {
                $this->runtime->applyLegacyStatus(
                    $this->organizationId,
                    $propertyId,
                    'published',
                    isset($input['status_note']) ? (string) $input['status_note'] : null,
                    $this->actor($userId),
                );
                $this->compatibility->recordActivity($this->organizationId, $propertyId, $userId, 'status_change', 'Об’єкт опубліковано', 'Публікація виконана через canonical Listing/Publication runtime.');
                return ['ok' => true, 'message' => 'Об’єкт опубліковано через canonical Listing/Publication runtime.'];
            }

            if ($action === 'share_link') {
                $body = trim((string) ($input['activity_body'] ?? 'Публічне посилання на картку об’єкта передано клієнту.'));
                $this->compatibility->recordActivity($this->organizationId, $propertyId, $userId, 'share', 'Посилання відправлено клієнту', $body);
                return ['ok' => true, 'message' => 'Відправку посилання зафіксовано в журналі.'];
            }

            if ($action === 'schedule_action') {
                $title = trim((string) ($input['next_action_title'] ?? ''));
                $dueAt = trim((string) ($input['next_action_due_at'] ?? ''));
                if ($title === '' || $dueAt === '') return ['ok' => false, 'message' => 'Вкажіть наступну дію і дедлайн.'];
                $metadata = [
                    'next_action_title' => mb_substr($title, 0, 180),
                    'next_action_due_at' => $dueAt,
                    'next_action_note' => isset($input['next_action_note']) ? mb_substr((string) $input['next_action_note'], 0, 500) : null,
                ];
                $this->compatibility->syncOperationalMetadata($this->organizationId, $propertyId, $metadata);
                $this->compatibility->recordActivity($this->organizationId, $propertyId, $userId, 'next_action', 'Наступну дію заплановано', $title . ' / ' . $dueAt);
                return ['ok' => true, 'message' => 'Наступну дію заплановано.'];
            }

            if ($action === 'next_stage') {
                $property = $this->legacyOperations->property($propertyId);
                if ($property === null) return ['ok' => false, 'message' => 'Обʼєкт не знайдено.'];
                $current = $this->workflow->stage((string) ($property['operational_stage'] ?? 'intake')) ?: 'intake';
                $next = $this->workflow->nextStage($current);
                if ($next === '') return ['ok' => false, 'message' => 'Для цього обʼєкта немає наступного етапу.'];
                if ($next === 'published' && !$this->workflow->isPublicStatus((string) ($property['status'] ?? ''))) {
                    return ['ok' => false, 'message' => 'Для переходу в роботу на ринку спочатку опублікуйте обʼєкт.'];
                }
                $candidate = array_merge($property, [
                    'operational_stage' => $next,
                    'next_action_title' => $input['next_action_title'] ?? ($property['next_action_title'] ?? null),
                    'next_action_due_at' => $input['next_action_due_at'] ?? ($property['next_action_due_at'] ?? null),
                    'next_action_note' => $input['next_action_note'] ?? ($property['next_action_note'] ?? null),
                ]);
                $issues = $this->workflow->stageIssues($candidate, count($this->legacyOperations->images($propertyId)));
                if ($issues !== []) return ['ok' => false, 'message' => 'Наступний етап поки недоступний: ' . implode(', ', $issues) . '.'];
                $this->compatibility->syncOperationalMetadata($this->organizationId, $propertyId, [
                    'operational_stage' => $next,
                    'next_action_title' => $candidate['next_action_title'] ?? null,
                    'next_action_due_at' => $candidate['next_action_due_at'] ?? null,
                    'next_action_note' => $candidate['next_action_note'] ?? null,
                ]);
                $this->compatibility->recordActivity($this->organizationId, $propertyId, $userId, 'stage_change', 'Етап роботи змінено', $this->workflow->stageLabel($current) . ' → ' . $this->workflow->stageLabel($next), $current, $next);
                return ['ok' => true, 'message' => 'Етап роботи оновлено.', 'stage' => $next];
            }

            return ['ok' => false, 'message' => 'Невідома швидка дія.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Не вдалося виконати дію: ' . $e->getMessage()];
        }
    }

    public function readiness(int $propertyId): array { return $this->legacyOperations->readiness($propertyId); }

    private function actor(?int $userId): ?string
    {
        return $userId !== null && $userId > 0 ? 'user:' . $userId : null;
    }
}
