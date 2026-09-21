<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final readonly class AIPanelController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private AgentRunReadModelInterface $runs,
        private AgentRunViewFactory $views,
        private UIContextFactory $uiContexts,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            throw new AccessDeniedHttpException('AI panel requires authentication.');
        }

        $workspaceId = trim((string) $request->query->get('workspace', ''));
        $entity = $this->entity((string) $request->query->get('entity', ''));
        $uiContext = null;
        $contextError = null;

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'ai',
            activeSection: $this->section($workspaceId),
            activeItem: $workspaceId,
        );

        if ($workspaceId !== '') {
            try {
                $uiContext = $this->uiContexts->create(
                    tenant: $tenant,
                    context: $context,
                    workspaceId: $workspaceId,
                    entity: $entity,
                    capabilities: [
                        'agent.result.read',
                        'agent.action.inspect',
                        'ui.action.propose',
                    ],
                );
            } catch (InvalidArgumentException) {
                $contextError = 'Workspace AI context is unavailable.';
            }
        }

        $runProjections = $entity !== null
            ? $this->runs->recentForSubject(
                $tenant->organizationId()->value(),
                $entity->type,
                $entity->id,
                12,
            )
            : $this->runs->recentForOrganization($tenant->organizationId()->value(), 12);

        $runViews = array_map(
            fn ($run): AgentRunViewModel => $this->views->create($tenant, $context, $run),
            $runProjections,
        );

        return new Response(
            $this->twig->render('experience/ai/ai_panel_frame.html.twig', [
                'runs' => $runViews,
                'uiContext' => $uiContext,
                'contextError' => $contextError,
                'workspaceId' => $workspaceId,
                'entity' => $entity,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    private function entity(string $key): ?EntityRef
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        try {
            return EntityRef::fromKey($key);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function section(string $workspaceId): string
    {
        if ($workspaceId === '') {
            return '';
        }

        $parts = explode('.', $workspaceId, 2);

        return $parts[0];
    }
}
