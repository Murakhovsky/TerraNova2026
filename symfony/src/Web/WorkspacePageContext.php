<?php
declare(strict_types=1);

namespace App\Web;

use App\Security\LegacySessionReader;
use App\Web\Navigation\NavigationBuilder;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\Request;

final readonly class WorkspacePageContext
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private LegacySessionReader $sessions,
        private NavigationBuilder $navigation,
    ) {
    }

    public function current(): ?TenantContext
    {
        return $this->tenants->current();
    }

    /** @param list<string> $assets @return array<string,mixed> */
    public function variables(
        Request $request,
        TenantContext $tenant,
        string $title,
        string $active,
        array $assets = [],
        ?string $workspaceSection = null,
    ): array {
        $role = $tenant->role()->value();
        $team = $tenant->isManager();

        return [
            'title' => $title,
            'metaTitle' => $title . ' | Terra Nova COS',
            'metaRobots' => 'noindex,nofollow',
            'workspaceSection' => $workspaceSection ?? ($team ? $active : ''),
            'workspaceActive' => $active,
            'workspaceActiveSection' => $this->navigation->activeSection($active),
            'pageAssetEntries' => $assets,
            'csrfToken' => $this->csrf($request),
            'currentUser' => [
                'id' => (int) $tenant->userId()->value(),
                'role' => $role,
            ],
            'role' => $role,
            'isTeam' => $team,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $team ? $this->navigation->workspace($tenant) : ['primary' => [], 'utility' => []],
            'portalNavigation' => $team ? ['primary' => [], 'utility' => []] : $this->navigation->portal($tenant),
            'interfaceSurface' => $team ? 'workspace' : 'portal',
        ];
    }

    public function csrf(Request $request): string
    {
        $sessionId = (string) $request->cookies->get($this->sessions->cookieName(), '');
        return $this->sessions->csrfToken($sessionId) ?? '';
    }
}
