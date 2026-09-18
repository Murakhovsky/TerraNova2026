<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class SweepCrmInboxCommand implements CommandInterface
{
    public function __construct(public int $limit = 100)
    {
    }
}
