<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

enum ActorKind: string
{
    case HUMAN = 'human';
    case AGENT = 'agent';
    case SYSTEM = 'system';
}
