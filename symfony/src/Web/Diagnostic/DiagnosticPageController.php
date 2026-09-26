<?php
declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Module\ActiveModuleResolver;
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
        private NavigationBuilder $navigation,
        private DiagnosticRuntimeService $runtime,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function report(Request $request, string $session): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if (!$this->diagnosticEnabled($tenant)) {
            return new Response('Not Found', 404);
        }

        try {
            $envelope = $this->runtime->report($tenant->organizationId()->value(), $session);
        } catch (Throwable) {
            return new Response('Diagnostic report was not found.', 404);
        }

        return $this->render($request, $tenant, 'diagnostic_report/show', [
            'metaTitle' => 'Diagnostic Report | COS',
            'workspaceActive' => 'diagnostics',
            'sessionId' => $session,
            'reportEnvelope' => $envelope,
            'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
        ]);
    }

    private function authenticated(): TenantContext|Response
    {
        return $this->tenants->current() ?? new RedirectResponse('/auth/login');
    }

    private function diagnosticEnabled(TenantContext $tenant): bool
    {
        return $this->modules->isEnabled($tenant->organizationId()->value(), 'diagnostic');
    }

    /** @param array<string,mixed> $extra */
    private function render(Request $request, TenantContext $tenant, string $view, array $extra): Response
    {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'workspaceSection' => 'cos',
            'workspaceActive' => 'diagnostics',
            'workspaceActiveSection' => 'cos',
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => $tenant->isManager(),
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response(
            $this->renderer->render($request, $view, $variables),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }


}
