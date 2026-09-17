<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

enum ToolEffect: string
{
    case READ = 'READ';
    case WRITE = 'WRITE';
    case EXTERNAL = 'EXTERNAL';

    public function hasSideEffects(): bool
    {
        return $this !== self::READ;
    }
}
