<?php
declare(strict_types=1);

namespace Domains\Property\Application\UseCase;

use Domains\Property\Application\Contract\PropertyModerationInterface;
use Domains\Property\Application\Contract\PropertyModerationRepositoryInterface;
use Domains\Property\Application\Contract\PropertyNotificationInterface;

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
    ) {
    }

    public function submissions(string $status = ''): array
    {
        return $this->submissions->submissions($status);
    }

    public function submission(int $id): ?array
    {
        return $this->submissions->submission($id);
    }

    public function counts(): array
    {
        return $this->submissions->counts();
    }

    public function submissionMedia(int $id): array
    {
        return $this->submissions->submissionMedia($id);
    }

    public function moderate(int $id, string $action, string $note = ''): array
    {
        $status = self::ACTION_STATUSES[$action] ?? null;
        if ($status === null) {
            return ['ok' => false, 'message' => 'Невідома дія модерації.'];
        }

        $result = $action === 'publish'
            ? $this->submissions->publish($id, $note)
            : $this->submissions->setStatus($id, $status, $note);

        if (!empty($result['ok'])) {
            $this->notifications?->notifySubmissionStatus(
                $id,
                $status,
                $note,
                !empty($result['property_id']) ? (int) $result['property_id'] : null,
            );
        }

        return $result;
    }
}
