<?php
declare(strict_types=1);

namespace Kernel\Queue\Handler;

use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Job;

final readonly class ModuleAwareJobHandler implements JobHandlerInterface
{
    public function __construct(
        private string $moduleId,
        private JobHandlerInterface $inner,
        private ActiveModuleResolver $modules,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->moduleId)) {
            throw new InvalidArgumentException(sprintf('Invalid module id: %s.', $this->moduleId));
        }
    }

    public function supports(string $type): bool
    {
        return $this->inner->supports($type);
    }

    public function handle(Job $job): void
    {
        if (!$this->modules->isEnabled($job->organizationId, $this->moduleId)) {
            return;
        }

        $this->inner->handle($job);
    }
}
