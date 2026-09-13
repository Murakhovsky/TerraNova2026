<?php
declare(strict_types=1);

namespace Kernel\Action\Service;

use Kernel\Action\Action;
use Kernel\Action\Contract\ActionExecutionGateInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use RuntimeException;

final readonly class ModuleActionExecutionGate implements ActionExecutionGateInterface
{
    public function __construct(
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function assertExecutable(Action $action): void
    {
        $moduleId = $this->domains->ownerOfAction($action->type);
        if ($moduleId === null) {
            throw new RuntimeException(sprintf(
                'Action type %s has no owning domain module.',
                $action->type,
            ));
        }
        if (!$this->modules->isEnabled($action->organizationId, $moduleId)) {
            throw new RuntimeException(sprintf(
                'Module %s is disabled for organization %s.',
                $moduleId,
                $action->organizationId,
            ));
        }
    }
}
