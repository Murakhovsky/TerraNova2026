<?php
declare(strict_types=1);

namespace Domains\Service\Domain;

use InvalidArgumentException;

final class ServiceTicketLifecycle
{
    public const OPEN = 'open';
    public const ASSIGNED = 'assigned';
    public const ESCALATED = 'escalated';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';

    public static function assertMutable(string $status, string $operation): void
    {
        if (in_array($status, [self::RESOLVED, self::CLOSED], true)) {
            throw new InvalidArgumentException(sprintf(
                'Service ticket in status %s cannot %s.',
                $status,
                $operation,
            ));
        }
        self::assertKnown($status);
    }

    public static function assertResolvable(string $status): void
    {
        self::assertMutable($status, 'be resolved');
    }

    public static function assertClosable(string $status): void
    {
        self::assertKnown($status);
        if ($status !== self::RESOLVED) {
            throw new InvalidArgumentException('Service ticket must be resolved before close.');
        }
    }

    public static function assignmentStatus(string $currentStatus): string
    {
        self::assertMutable($currentStatus, 'be assigned');
        return $currentStatus === self::ESCALATED ? self::ESCALATED : self::ASSIGNED;
    }

    public static function assertKnown(string $status): void
    {
        if (!in_array($status, [self::OPEN, self::ASSIGNED, self::ESCALATED, self::RESOLVED, self::CLOSED], true)) {
            throw new InvalidArgumentException('Unknown Service ticket status: ' . $status);
        }
    }
}
