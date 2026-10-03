<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class ContinueEngineeringWorkflowsCommand implements CommandInterface
{
    public function __construct(public string $trigger = 'scheduler') {}
}
