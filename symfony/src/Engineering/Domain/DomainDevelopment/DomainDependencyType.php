<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum DomainDependencyType: string
{
    case REQUIRES = 'REQUIRES';
    case BLOCKS = 'BLOCKS';
    case EXTENDS = 'EXTENDS';
    case IMPLEMENTS = 'IMPLEMENTS';
    case USES = 'USES';
    case MIGRATES = 'MIGRATES';
    case INTEGRATES_WITH = 'INTEGRATES_WITH';

    public function blocksScheduling(): bool
    {
        return in_array($this, [self::REQUIRES, self::EXTENDS, self::IMPLEMENTS, self::MIGRATES, self::INTEGRATES_WITH], true);
    }
}
