<?php
declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Security\LegacySessionReader;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class DiagnosticPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private LegacySessionReader $sessions,
        private NavigationBuilder $navigation,
        private DiagnosticMethodologyAccess $access,
        private DiagnosticRuntimeService $runtime,
    ) {
    }

    public function methodologyStudio(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        if (!$this->access->allows(
            $tenant->organizationId()->value(),
            (int) $tenant->userId()->value(),
            DiagnosticMethodologyAccess::VIEW,
        )) {
            return new Response('Forbidden', 403);
        }

        return $this->render($request, $tenant, 'Diagnostic Methodology Studio', 'methodology_studio/index', [
            'pageAssetEntries' => ['diagnostics-methodology-studio'],
            'csrfToken' => $this->csrf($request),
        ]);
    }

    public function report(Request $request, string $session): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            $envelope = $this->runtime->report($tenant->organizationId()->value(), $session);
        } catch (Throwable) {
            return new Response('Diagnostic report was not found.', 404);
        }

        return $this->render($request, $tenant, 'Diagnostic Report', 'diagnostic_report/show', [
            'sessionId' => $session,
            'reportEnvelope' => $envelope,
            'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function render(Request $request, TenantContext $tenant, string $title, string $view, array $extra = []): Response
    {
        $role = $tenant->role()->value();
        return new Response($this->renderer->render($request, $view, array_replace([
            'title' => $title,
            'metaTitle' => $title . ' | COS',
            'metaRobots' => 'noindex,nofollow',
            'workspaceSection' => 'cos',
            'workspaceActive' => 'diagnostics',
            'workspaceActiveSection' => 'cos',
            'pageAssetEntries' => [],
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra)), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', 403);
        }

        return $tenant;
    }

    private function csrf(Request $request): string
    {
        $sessionId = (string) $request->cookies->get($this->sessions->cookieName(), '');
        return $this->sessions->csrfToken($sessionId) ?? '';
    }
}
