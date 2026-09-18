<?php
declare(strict_types=1);

namespace App\Application\System\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class SchedulerHeartbeatCommand implements CommandInterface
{
    public function __construct(public string $token)
    {
    }
}
