<?php
declare(strict_types=1);

namespace App\Web\Diagnostic;

use App\Web\Phtml\PhtmlRenderer;
use App\Web\WorkspacePageContext;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class DiagnosticPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private WorkspacePageContext $page,
        private DiagnosticRuntimeService $runtime,
        private DiagnosticMethodologyAccess $access,
    ) {
    }

    public function report(Request $request, string $session): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) return $tenant;

        $sessionId = trim($session);
        try {
            $envelope = $this->runtime->report($tenant->organizationId()->value(), $sessionId);
        } catch (Throwable) {
            return new Response('Diagnostic report was not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $variables = array_replace(
            $this->page->variables($request, $tenant, 'Diagnostic Report', 'diagnostics', [], null),
            [
                'sessionId' => $sessionId,
                'reportEnvelope' => $envelope,
                'report' => is_array($envelope['report'] ?? null) ? $envelope['report'] : [],
            ],
        );

        return new Response(
            $this->renderer->render($request, 'diagnostic_report/show', $variables),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function methodologyStudio(Request $request): Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) return $tenant;

        $organizationId = $tenant->organizationId()->value();
        $userId = (int) $tenant->userId()->value();
        if (!$this->access->allows($organizationId, $userId, DiagnosticMethodologyAccess::VIEW)) {
            return new Response('Forbidden', 403);
        }

        $variables = $this->page->variables(
            $request,
            $tenant,
            'Diagnostic Methodology Studio',
            'diagnostics',
            ['diagnostics-methodology-studio'],
            $tenant->isManager() ? 'cos' : null,
        );

        return new Response(
            $this->renderer->render($request, 'methodology_studio/index', $variables),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function authenticated(): TenantContext|Response
    {
        return $this->page->current() ?? new RedirectResponse('/auth/login');
    }
}
