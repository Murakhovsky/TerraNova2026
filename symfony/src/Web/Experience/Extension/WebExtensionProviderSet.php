<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Extension\Contract\ActionProviderInterface;
use App\Web\Experience\Extension\Contract\ActivityProviderInterface;
use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\DashboardWidgetProviderInterface;
use App\Web\Experience\Extension\Contract\EntityLinkProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\NotificationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WebExtensionProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceExtensionProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use Kernel\Module\ModuleExtensionPoint;
use Kernel\Module\ModuleExtensionRegistry;
use Kernel\Module\OrganizationModuleSnapshot;
use LogicException;

final readonly class WebExtensionProviderSet
{
    /**
     * @param array<string, WebExtensionProviderInterface> $providers
     */
    public function __construct(
        public WebExtensionContext $context,
        private ModuleExtensionRegistry $extensions,
        private OrganizationModuleSnapshot $modules,
        private array $providers,
    ) {
    }

    /** @return list<NavigationProviderInterface> */
    public function navigation(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_NAVIGATION, NavigationProviderInterface::class);
    }

    /** @return list<SearchProviderInterface> */
    public function search(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_SEARCH, SearchProviderInterface::class);
    }

    /** @return list<CommandProviderInterface> */
    public function commands(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_COMMANDS, CommandProviderInterface::class);
    }

    /** @return list<WorkspaceProviderInterface> */
    public function workspaces(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_WORKSPACE, WorkspaceProviderInterface::class);
    }

    /** @return list<WorkspaceExtensionProviderInterface> */
    public function workspaceExtensions(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_WORKSPACE_EXTENSIONS, WorkspaceExtensionProviderInterface::class);
    }

    /** @return list<DashboardWidgetProviderInterface> */
    public function dashboardWidgets(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_DASHBOARD_WIDGETS, DashboardWidgetProviderInterface::class);
    }

    /** @return list<EntityLinkProviderInterface> */
    public function entityLinks(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_ENTITY_LINKS, EntityLinkProviderInterface::class);
    }

    /** @return list<NotificationProviderInterface> */
    public function notifications(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_NOTIFICATIONS, NotificationProviderInterface::class);
    }

    /** @return list<ActivityProviderInterface> */
    public function activity(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_ACTIVITY, ActivityProviderInterface::class);
    }

    /** @return list<ActionProviderInterface> */
    public function actions(): array
    {
        return $this->resolve(ModuleExtensionPoint::WEB_ACTIONS, ActionProviderInterface::class);
    }

    /**
     * @template T of WebExtensionProviderInterface
     * @param class-string<T> $contract
     * @return list<T>
     */
    private function resolve(string $extensionPoint, string $contract): array
    {
        $resolved = [];

        foreach ($this->extensions->for($extensionPoint) as $contribution) {
            if (!$this->modules->isEnabled($contribution->moduleId)) {
                continue;
            }

            $provider = $this->providers[$contribution->serviceId] ?? throw new LogicException(sprintf(
                'Web extension service %s declared by module %s is not registered.',
                $contribution->serviceId,
                $contribution->moduleId,
            ));

            if (!$provider instanceof $contract) {
                throw new LogicException(sprintf(
                    'Web extension service %s for %s must implement %s.',
                    $contribution->serviceId,
                    $extensionPoint,
                    $contract,
                ));
            }

            $resolved[] = $provider;
        }

        return $resolved;
    }
}
