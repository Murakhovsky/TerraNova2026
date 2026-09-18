<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class RunSalesAutomationCommand implements CommandInterface
{
    public function __construct(
        public ?string $organizationId = null,
        public int $noActivityHours = 48,
        public int $limit = 200,
        public ?string $runId = null,
    ) {
    }
}
