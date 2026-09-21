<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

use App\Web\Experience\Extension\Model\WebExtensionContext;

final readonly class CoreCommandCatalog
{
    /** @return list<ShellCommandItem> */
    public function commands(WebExtensionContext $context): array
    {
        $commands = [
            new ShellCommandItem('core.home', 'Open Workspace Overview', '/admin', 'command', 'Core'),
            new ShellCommandItem('core.cos', 'Open COS Control Center', '/cos/control-center', 'command', 'Core'),
            new ShellCommandItem('core.analytics', 'Open Analytics', '/admin/analytics', 'command', 'Core'),
            new ShellCommandItem('core.administration', 'Open Administration', '/admin/content', 'command', 'Core'),
        ];

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
