<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

enum SyncDirection: string
{
    case PULL = 'pull';
    case PUSH = 'push';
    case BIDIRECTIONAL = 'bidirectional';
}
