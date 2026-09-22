<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum RelationshipStrength: string
{
    case Unknown = 'unknown';
    case None = 'none';
    case Weak = 'weak';
    case Medium = 'medium';
    case Strong = 'strong';

    public function weight(): int
    {
        return match($this){
            self::Unknown => 0,
            self::None => 0,
            self::Weak => 25,
            self::Medium => 60,
            self::Strong => 100,
        };
    }
}
