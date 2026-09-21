<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

enum ShellConnectionState: string
{
    case Live = 'live';
    case Reconnecting = 'reconnecting';
    case Offline = 'offline';
    case Stale = 'stale';
}
