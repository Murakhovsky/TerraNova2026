<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;

final readonly class SalesAgentToolPermissionChecker implements ToolPermissionCheckerInterface
{
    public function __construct(
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function allows(
        ToolPermission $permission,
        ToolDefinition $definition,
        ToolInvocation $invocation,
    ): bool {
        if ($definition->name() !== 'sales.action.propose'
            || $permission->name() !== 'tool.sales.action.propose.execute') {
            return false;
        }

        $input = $invocation->input();
        $agentName = trim((string) ($input['agent_name'] ?? ''));
        $actionType = trim((string) ($input['action_type'] ?? ''));
        if ($agentName === '' || $actionType === '') {
            return false;
        }

        if ($this->domains->ownerOfAgent($agentName) !== 'sales'
            || $this->domains->ownerOfAction($actionType) !== 'sales') {
            return false;
        }

        return $this->modules->isEnabled($invocation->organizationId()->value(), 'sales');
    }
}
