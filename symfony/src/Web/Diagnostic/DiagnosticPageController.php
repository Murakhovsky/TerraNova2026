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
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $organizationId = $tenant->organizationId()->value();
        $userId = (int) $tenant->userId()->value();
        if (!$this->access->allows($organizationId, $userId, DiagnosticMethodologyAccess::VIEW)) {
            return new Response('Forbidden', 403);
        }

        return $this->render($request, $tenant, 'methodology_studio/index', [
            'metaTitle' => 'Diagnostic Methodology Studio | COS',
            'active' => 'diagnostics',
            'pageAssetEntries' => ['diagnostics-methodology-studio'],
            'csrfToken' => $this->csrf($request),
        ]);
    }

    public function report(Request $request, string $session): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            $envelope = $this->runtime->report($tenant->organizationId()->value(), $session);
        } catch (Throwable) {
            return new Response('Diagnostic report was not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $this->render($request, $tenant, 'diagnostic_report/show', [
            'metaTitle' => 'Diagnostic Report | COS',
            'active' => 'diagnostics',
            'sessionId' => $session,
            'reportEnvelope' => $envelope,
            'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
            'pageAssetEntries' => [],
        ]);
    }

    private function authenticated(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        return $tenant ?? new RedirectResponse('/auth/login');
    }

    /** @param array<string,mixed> $extra */
    private function render(Request $request, TenantContext $tenant, string $view, array $extra): Response
    {
        $role = $tenant->role()->value();
        $isTeam = $tenant->isManager();
        $variables = array_replace([
            'interfaceSurface' => 'workspace',
            'workspaceSection' => '',
            'workspaceActive' => 'diagnostics',
            'workspaceActiveSection' => $this->navigation->activeSection('diagnostics'),
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => $isTeam,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $isTeam ? $this->navigation->workspace($tenant) : ['primary' => [], 'utility' => []],
            'portalNavigation' => $this->navigation->portal($tenant),
        ], $extra);

        return new Response(
            $this->renderer->render($request, $view, $variables),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function csrf(Request $request): string
    {
        $sessionId = (string) $request->cookies->get($this->sessions->cookieName(), '');
        return $this->sessions->csrfToken($sessionId) ?? '';
    }
}
