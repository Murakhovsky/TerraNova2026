<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DealChangeSet
{
    private function __construct(private array $changes)
    {
    }

    public static function fromArray(array $input): self
    {
        $changes = array_intersect_key($input, array_flip([
            'stage', 'status', 'priority', 'next_contact_at', 'assigned_user_id', 'deal_value', 'probability', 'expected_close_at',
        ]));
        if ($changes === []) {
            throw new InvalidArgumentException('No allowed Deal fields supplied.');
        }

        self::assertOneOf($changes, 'stage', PipelineStage::values());
        self::assertOneOf($changes, 'status', ClientCaseStatus::values());
        self::assertOneOf($changes, 'priority', SalesPriority::values());
        foreach (['next_contact_at', 'expected_close_at'] as $dateField) {
            if (isset($changes[$dateField]) && $changes[$dateField] !== '') {
                $changes[$dateField] = (new DateTimeImmutable((string) $changes[$dateField]))->format('Y-m-d H:i:s');
            } elseif (array_key_exists($dateField, $changes)) {
                $changes[$dateField] = null;
            }
        }
        if (array_key_exists('assigned_user_id', $changes)) $changes['assigned_user_id'] = max(1, (int) $changes['assigned_user_id']);
        if (array_key_exists('deal_value', $changes)) {
            $changes['deal_value'] = (float) $changes['deal_value'];
            if ($changes['deal_value'] < 0) throw new InvalidArgumentException('Deal value cannot be negative.');
        }
        if (array_key_exists('probability', $changes)) {
            $changes['probability'] = (float) $changes['probability'];
            if ($changes['probability'] < 0 || $changes['probability'] > 100) throw new InvalidArgumentException('Deal probability must be between 0 and 100.');
        }

        return new self($changes);
    }

    public function toArray(): array
    {
        return $this->changes;
    }

    private static function assertOneOf(array $changes, string $field, array $allowed): void
    {
        if (isset($changes[$field]) && !in_array($changes[$field], $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Invalid Deal %s: %s.', $field, (string) $changes[$field]));
        }
    }
}
