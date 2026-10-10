<?php
declare(strict_types=1);

namespace Kernel\Action\Service;

use Kernel\Action\Action;
use Kernel\Action\Contract\ActionExecutionGateInterface;
use Kernel\Action\Contract\FederatedActionAdmissionInterface;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;

final readonly class ModuleActionExecutionGate implements ActionExecutionGateInterface
{
    public function __construct(
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
        private ?FederatedActionAdmissionInterface $federation = null,
    ) {
    }

    public function assertExecutable(Action $action): void
    {
        $moduleId = $this->domains->ownerOfAction($action->type);
        if ($moduleId === null) {
            throw ExecutionFailureException::permanent(sprintf(
                'Action type %s has no owning domain module.',
                $action->type,
            ));
        }
        if (!$this->modules->isEnabled($action->organizationId, $moduleId)) {
            throw ExecutionFailureException::policyDenied(sprintf(
                'Module %s is disabled for organization %s.',
                $moduleId,
                $action->organizationId,
            ));
        }
        if ($action->idempotencyKey !== null
            && str_starts_with($action->idempotencyKey, 'fed:')) {
            if ($this->federation === null) {
                throw ExecutionFailureException::policyDenied('Federation Action admission is not installed.');
            }
            $this->federation->assertAuthorized($action);
        }
    }
}
