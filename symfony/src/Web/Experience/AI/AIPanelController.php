<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Experience\Shell\ShellConnectionState;
use Throwable;
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
        private WebExtensionCatalog $extensions,
        private AgentWorkspaceRuntime $runtime,
        private WorkspaceShellFactory $shells,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            throw new AccessDeniedHttpException('AI panel requires authentication.');
        }

        if (!AgentWorkspaceAccess::allows($tenant)) {
            throw new AccessDeniedHttpException('AI run history requires tenant manager access.');
        }

        $workspaceId = trim((string) $request->query->get('workspace', ''));
        $entityKey = trim((string) $request->query->get('entity', ''));
        $standalone = $request->query->get('view') === 'workspace';
        $uiContext = null;
        $entity = null;
        $contextError = null;
        $historyError = null;
        $runViews = [];
        $runtime = null;
        $workspaces = [];

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'ai',
            activeSection: $this->section($workspaceId),
            activeItem: $workspaceId,
        );

        try {
            $workspaces = $this->extensions->forContext($context)->workspaces();
            // Invalid or missing entity scope must not silently broaden to tenant history.
            if (strlen($workspaceId) > 120 || strlen($entityKey) > 240
                || ($entityKey !== '' && $workspaceId === '')) {
                throw new InvalidArgumentException('Invalid AI scope.');
            }
            $entity = $entityKey === '' ? null : EntityRef::fromKey($entityKey);
            if ($workspaceId !== '') {
                $workspace = null;
                foreach ($workspaces as $candidate) {
                    if ($candidate->id === $workspaceId) {
                        $workspace = $candidate;
                        break;
                    }
                }
                if ($workspace === null || !AgentWorkspaceScope::permitsEntity($workspace, $entity)) {
                    throw new InvalidArgumentException('Workspace entity scope unavailable.');
                }
                $uiContext = $this->uiContexts->create(
                    tenant: $tenant,
                    context: $context,
                    workspaceId: $workspaceId,
                    entity: $entity,
                    capabilities: ['agent.result.read', 'agent.action.inspect', 'ui.action.propose'],
                );
                $runtime = $this->runtime->inspect($tenant, $uiContext);
                // Only an owner-resolved campaign may be used for the first Hermes binding.
                if ($runtime !== null && $entity !== null && $runtime['campaign'] === null) {
                    throw new InvalidArgumentException('Agent subject scope unavailable.');
                }
            }
        } catch (Throwable) {
            $uiContext = null;
            $runtime = null;
            $contextError = 'Workspace AI context is unavailable. Select an accessible workspace and entity.';
        }

        if ($contextError === null) {
            try {
                $organization = $tenant->organizationId()->value();
                $runProjections = $entity !== null
                    ? $this->runs->recentForSubject($organization, $entity->type, $entity->id, 12)
                    : ($workspaceId === '' ? $this->runs->recentForOrganization($organization, 12) : []);

                foreach ($runProjections as $run) {
                    if (!AgentWorkspaceScope::permitsRun($tenant, $entity, $run)) {
                        continue;
                    }
                    $runViews[] = $this->views->create($tenant, $context, $run);
                }
            } catch (Throwable) {
                $runViews = [];
                $historyError = 'Agent history is unavailable. Manual workspaces remain available.';
            }
        }

        $data = [
            'runs' => $runViews,
            'uiContext' => $uiContext,
            'contextError' => $contextError,
            'historyError' => $historyError,
            'workspaceId' => $workspaceId,
            'entity' => $entity,
            'workspaces' => $workspaces,
            'runtime' => $runtime,
            'standalone' => $standalone,
            'csrfToken' => $this->csrf->token($request),
            'currentUserId' => $tenant->userId()->value(),
            'planningKey' => 'ai-' . bin2hex(random_bytes(12)),
        ];
        if ($standalone) {
            $data['shell'] = $this->shells->create(
                $tenant, $context, 'AI Workspace',
                [new ShellBreadcrumb('AI Workspace')], aiAvailable: false,
                connectionState: ShellConnectionState::Stale,
            );
        }

        return new Response(
            $this->twig->render(
                $standalone ? 'experience/ai/workspace.html.twig' : 'experience/ai/ai_panel_frame.html.twig',
                $data,
            ),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
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
