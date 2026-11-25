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
        foreach (['stage', 'stage_id', 'pipeline_id'] as $stageField) {
            if (array_key_exists($stageField, $input)) throw new InvalidArgumentException('Deal stage can only be changed through ChangeDealStage.');
        }
        $changes = array_intersect_key($input, array_flip([
            'priority', 'next_contact_at', 'deal_value', 'currency', 'probability', 'expected_close_at',
        ]));
        if ($changes === []) {
            throw new InvalidArgumentException('No allowed Deal fields supplied.');
        }

        self::assertOneOf($changes, 'priority', SalesPriority::values());
        foreach (['next_contact_at', 'expected_close_at'] as $dateField) {
            if (isset($changes[$dateField]) && $changes[$dateField] !== '') {
                $changes[$dateField] = (new DateTimeImmutable((string) $changes[$dateField]))->format('Y-m-d H:i:s');
            } elseif (array_key_exists($dateField, $changes)) {
                $changes[$dateField] = null;
            }
        }
        if (array_key_exists('deal_value',$changes) || array_key_exists('currency',$changes)) {
            if (!is_string($changes['deal_value']??null) || preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$changes['deal_value'])!==1
                || !is_string($changes['currency']??null) || !SalesCurrency::accepts($changes['currency'])) {
                throw new InvalidArgumentException('Deal value requires an exact nonnegative decimal string and supported currency together.');
            }
        }
        if (array_key_exists('probability', $changes)) {
            if (!is_numeric($changes['probability'])) throw new InvalidArgumentException('Invalid deal probability.');
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
