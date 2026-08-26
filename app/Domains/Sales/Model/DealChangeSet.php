<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DealChangeSet
{
    private const STAGES = ['new', 'qualification', 'need_defined', 'matching', 'viewing', 'negotiation', 'deal', 'aftercare', 'repeat', 'paused', 'lost'];
    private const STATUSES = ['active', 'paused', 'closed', 'lost'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private function __construct(private array $changes)
    {
    }

    public static function fromArray(array $input): self
    {
        $changes = array_intersect_key($input, array_flip(['stage', 'status', 'priority', 'next_contact_at']));
        if ($changes === []) {
            throw new InvalidArgumentException('No allowed Deal fields supplied.');
        }

        self::assertOneOf($changes, 'stage', self::STAGES);
        self::assertOneOf($changes, 'status', self::STATUSES);
        self::assertOneOf($changes, 'priority', self::PRIORITIES);
        if (isset($changes['next_contact_at']) && $changes['next_contact_at'] !== '') {
            $changes['next_contact_at'] = (new DateTimeImmutable((string) $changes['next_contact_at']))->format('Y-m-d H:i:s');
        } elseif (array_key_exists('next_contact_at', $changes)) {
            $changes['next_contact_at'] = null;
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
