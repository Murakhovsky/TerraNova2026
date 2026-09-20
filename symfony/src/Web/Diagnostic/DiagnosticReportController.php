<?php
declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class DiagnosticReportController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private DiagnosticRuntimeService $runtime,
    ) {
    }

    public function show(Request $request, string $session): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

        try {
            $envelope = $this->runtime->report($tenant->organizationId()->value(), $session);
        } catch (Throwable) {
            return new Response('Diagnostic report was not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $role = $tenant->role()->value();
        $isTeam = $tenant->isManager();
        $html = $this->renderer->render($request, 'diagnostic_report/show', [
            'metaTitle' => 'Diagnostic Report | COS',
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => $isTeam ? 'workspace' : 'portal',
            'active' => 'diagnostics',
            'sessionId' => $session,
            'reportEnvelope' => $envelope,
            'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => $isTeam,
            'workspaceNavigation' => $isTeam ? $this->navigation->workspace($tenant) : ['primary' => [], 'utility' => []],
            'workspaceActiveSection' => 'cos',
            'portalNavigation' => $this->navigation->portal($tenant),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
