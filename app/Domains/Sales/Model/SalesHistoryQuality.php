<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum SalesHistoryQuality: string
{
    case Complete = 'COMPLETE';
    case Partial = 'PARTIAL';
    case Estimated = 'ESTIMATED';

    public static function fromStorage(string $value): self
    {
        return self::tryFrom(strtoupper(trim($value))) ?? self::Partial;
    }
}
