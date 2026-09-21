<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\ShellViewModel;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final readonly class RealtimePlatformPreviewController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ProviderBackedShellNavigation $navigation,
        private RealtimeTopicFactory $topics,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->isManager()) {
            throw new AccessDeniedHttpException('Realtime Platform preview requires manager access.');
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'cos',
            activeItem: 'realtime',
        );
        $navigation = $this->navigation->compose($context);
        $role = ucfirst($tenant->role()->value());

        $shell = new ShellViewModel(
            title: 'Realtime Platform',
            tenantLabel: $tenant->organizationId()->value(),
            userLabel: $role . ' Workspace User',
            userInitials: strtoupper(substr($role, 0, 2)),
            activeSection: 'cos',
            primaryNavigation: $navigation['primary'],
            utilityNavigation: $navigation['utility'],
            breadcrumbs: [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Realtime'),
            ],
            commands: $navigation['commands'],
            notificationCount: 0,
            activityCount: 0,
            connectionState: ShellConnectionState::Live,
            aiAvailable: true,
        );

        return new Response(
            $this->twig->render('experience/realtime_platform_preview.html.twig', [
                'shell' => $shell,
                'topic' => $this->topics->workspace(
                    $tenant->organizationId()->value(),
                    'realtime.preview',
                ),
                'csrfToken' => $this->csrf->token($request),
                'published' => $request->query->getBoolean('published'),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
