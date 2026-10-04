<?php

declare(strict_types=1);

namespace App\Web\Experience\Security;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: -32)]
final readonly class WebAccessDeniedResponseSubscriber
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private Environment $twig,
    ) {
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->shouldReplace($request, $response)) {
            return;
        }

        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return;
        }

        $path = $request->getPathInfo();
        [$requiredAccess, $requiredPermission] = $this->requirementsFor($path);

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: '',
            activeItem: '',
        );
        $shell = $this->shells->create($tenant, $context, 'Доступ обмежено', [
            new ShellBreadcrumb('Workspace', '/cabinet'),
            new ShellBreadcrumb('Доступ'),
        ]);

        $response->setContent($this->twig->render('experience/security/access_denied.html.twig', [
            'shell' => $shell,
            'requestedPath' => $path,
            'currentRole' => $tenant->role()->value(),
            'organizationId' => $tenant->organizationId()->value(),
            'userId' => $tenant->userId()->value(),
            'requiredAccess' => $requiredAccess,
            'requiredPermission' => $requiredPermission,
        ]));
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }

    private function shouldReplace(Request $request, Response $response): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($response->getStatusCode() !== Response::HTTP_FORBIDDEN) {
            return false;
        }

        return trim((string) $response->getContent()) === 'Forbidden';
    }

    /** @return array{0:string,1:?string} */
    private function requirementsFor(string $path): array
    {
        if (str_starts_with($path, '/admin/users')) {
            return ['роль admin', 'cos.tenant.admin'];
        }

        if (str_starts_with($path, '/admin/engineering')) {
            return ['роль manager або admin', 'cos.tenant.manage'];
        }

        return ['роль manager/admin або спеціальна capability цього розділу', null];
    }
}
