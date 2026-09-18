<?php
declare(strict_types=1);

namespace App\Application\System\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class DrainSalesOutboxCommand implements CommandInterface
{
    public function __construct(
        public int $limit = 100,
        public ?string $workerId = null,
    ) {
    }
}
