<?php
declare(strict_types=1);

namespace App\Web\Operations;

/** Presentation vocabulary only; never written to a runtime store. */
enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingApproval = 'waiting_approval';
    case Blocked = 'blocked';
    case Verified = 'completed_verified';
    case Unverified = 'completed_unverified';
    case Unknown = 'external_outcome_unknown';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reconciliation = 'reconciliation_required';

    public static function fromNative(string $source, string $state, bool $verified = false, bool $retryScheduled = false): self
    {
        $state = strtolower($state);
        if ($source === 'approval') {
            return match ($state) {
                'pending' => self::WaitingApproval,
                'approved' => self::Unverified, // a decision, not execution
                'rejected', 'cancelled' => self::Cancelled,
                default => self::Blocked,
            };
        }
        if ($source === 'import') {
            return match ($state) {
                'staged' => self::Blocked,
                'applying' => self::Running,
                'applied' => self::Unverified,
                default => self::Blocked,
            };
        }
        return match ($state) {
            'created', 'queued', 'pending' => self::Queued,
            'running', 'claimed' => self::Running,
            'proposed', 'waiting', 'blocked' => self::Blocked,
            'pending_approval', 'waiting_approval' => self::WaitingApproval,
            'completed', 'succeeded' => $verified && $source === 'workflow' ? self::Verified : self::Unverified,
            'ambiguous', 'external_outcome_unknown' => self::Unknown,
            'reconciliation_required' => self::Reconciliation,
            'failed', 'dead' => $retryScheduled ? self::Queued : self::Failed,
            'cancelled', 'rejected' => self::Cancelled,
            default => self::Blocked,
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::WaitingApproval, self::Blocked, self::Unverified, self::Unknown, self::Failed, self::Reconciliation], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'У черзі',
            self::Running => 'Виконується',
            self::WaitingApproval => 'Потребує погодження',
            self::Blocked => 'Заблоковано / очікує',
            self::Verified => 'Виконання підтверджено',
            self::Unverified => 'Завершено, результат не підтверджено',
            self::Unknown => 'Зовнішній результат невідомий',
            self::Failed => 'Помилка',
            self::Cancelled => 'Скасовано / відхилено',
            self::Reconciliation => 'Потрібне звіряння',
        };
    }
}
