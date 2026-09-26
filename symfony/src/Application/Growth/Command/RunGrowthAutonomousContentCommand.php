<?php
declare(strict_types=1);

namespace App\Application\Growth\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class RunGrowthAutonomousContentCommand implements CommandInterface
{
    public function __construct(
        public string $trigger='scheduler',
        public ?int $atUnix=null,
    ) {}
}
