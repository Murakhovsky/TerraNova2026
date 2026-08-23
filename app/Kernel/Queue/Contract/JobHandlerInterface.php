<?php
declare(strict_types=1);
namespace Kernel\Queue\Contract;

use Kernel\Queue\Job;

interface JobHandlerInterface
{
    public function supports(string $type): bool;
    public function handle(Job $job): void;
}
