<?php
declare(strict_types=1);

namespace Domains\Property\Application\UseCase;

use Domains\Property\Application\Contract\PropertyModerationInterface;
use Domains\Property\Application\Contract\PropertyModerationRepositoryInterface;
use Domains\Property\Application\Contract\PropertyNotificationInterface;
use Domains\Property\Application\Service\PropertyIdentityWorkflowService;
use Throwable;

final readonly class PropertyModerationService implements PropertyModerationInterface
{
    private const ACTION_STATUSES = [
        'review' => 'in_review',
        'needs_changes' => 'needs_changes',
        'approve' => 'approved',
        'reject' => 'rejected',
        'spam' => 'spam',
        'publish' => 'published',
    ];

    public function __construct(
        private PropertyModerationRepositoryInterface $submissions,
        private ?PropertyNotificationInterface $notifications = null,
        private ?PropertyIdentityWorkflowService $identity = null,
    ) {
    }

    public function submissions(string $status = ''): array { return $this->submissions->submissions($status); }
    public function submission(int $id): ?array { return $this->submissions->submission($id); }
    public function counts(): array { return $this->submissions->counts(); }
    public function submissionMedia(int $id): array { return $this->submissions->submissionMedia($id); }

    public function moderate(int $id, string $action, string $note = ''): array
    {
        $status = self::ACTION_STATUSES[$action] ?? null;
        if ($status === null) return ['ok' => false, 'message' => 'Невідома дія модерації.'];

        $beforePublish = $action === 'publish' ? $this->submissions->submission($id) : null;
        $result = $action === 'publish'
            ? $this->submissions->publish($id, $note)
            : $this->submissions->setStatus($id, $status, $note);

        $publishedPropertyId = (int) ($result['property_id'] ?? ($beforePublish['property_id'] ?? 0));
        if (!empty($result['ok']) && $action === 'publish' && $publishedPropertyId > 0 && $this->identity !== null) {
            $submission = $this->submissions->submission($id) ?? $beforePublish;
            $organizationId = trim((string) ($submission['organization_id'] ?? ''));
            if ($organizationId !== '') {
                try {
                    $result['identity'] = $this->identity->resolvePublished($organizationId, $id, $publishedPropertyId);
                } catch (Throwable $error) {
                    $result['identity'] = ['status' => 'error', 'message' => mb_substr($error->getMessage(), 0, 500)];
                }
            }
        }

        if (!empty($result['ok'])) {
            $this->notifications?->notifySubmissionStatus($id, $status, $note, $publishedPropertyId > 0 ? $publishedPropertyId : null);
        }
        return $result;
    }
}
