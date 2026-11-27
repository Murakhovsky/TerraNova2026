<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use App\Web\Experience\Extension\Model\WebExtensionContext;

final readonly class CoreCommandCatalog
{
    public function __construct(private ?TenantContextProviderInterface $tenants = null) {}

    public function canOpenOperations(WebExtensionContext $context): bool
    {
        $actor = $this->tenants?->current();
        return $actor !== null
            && $actor->organizationId()->value() === $context->organizationId
            && $actor->role()->value() === $context->role
            && $actor->isManager()
            && $actor->allows(TenantPermissions::ACCESS)
            && $actor->allows(TenantPermissions::MANAGE);
    }

    /** @return list<ShellCommandItem> */
    public function commands(WebExtensionContext $context): array
    {
        $commands = [
            new ShellCommandItem('core.home', 'Open Workspace Overview', '/admin', 'command', 'Core'),
            new ShellCommandItem('core.unified', 'Мої робочі простори', '/workspace/unified', 'navigation', 'Workspace'),
            new ShellCommandItem('core.cos', 'Open COS Control Center', '/cos/control-center', 'command', 'Core'),
            new ShellCommandItem('core.analytics', 'Open Analytics', '/admin/analytics', 'command', 'Core'),
            new ShellCommandItem('core.administration', 'Open Administration', '/admin/content', 'command', 'Core'),
        ];
        if ($this->canOpenOperations($context)) {
            $commands[] = new ShellCommandItem('core.operations', 'Центр виконання / Operations Center', '/workspace/operations', 'navigation', 'COS');
        }


        if (in_array($context->role, ['manager', 'admin'], true)) {
            $commands[] = new ShellCommandItem(
                'core.engineering',
                'Open Engineering Workflows',
                '/admin/engineering',
                'command',
                'Admin',
            );
        }

        if ($context->role === 'admin') {
            $commands[] = new ShellCommandItem(
                'core.users',
                'Open User Administration',
                '/admin/users',
                'command',
                'Admin',
            );
        }

        return $commands;
    }
}
