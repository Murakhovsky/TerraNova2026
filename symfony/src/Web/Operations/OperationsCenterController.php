<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Application\Operations\Command\OperationsMutationCommand;
use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Persistence\Federation\FederationGoalStore;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\ExperienceMode;
use App\Web\Experience\Extension\EntityLinkResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;
use Twig\Environment;

/** UX-05. No executor, no direct mutations, no reliance on AI or Mercure availability. */
#[AsController]
final readonly class OperationsCenterController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private OperationsSnapshot $snapshots,
        private OperationsDecisionActions $decisions,
        private WorkspaceShellFactory $shells,
        private EntityLinkResolver $links,
        private RealtimeTopicFactory $topics,
        private FederationExperiencePreferenceStore $preferences,
        private SessionCsrfValidator $csrf,
        private CommandBusInterface $commands,
        private Environment $twig,
        private FederationGoalStore $goals,
        private OperationsRecoveryRead $recovery,
        private OperationsRecoveryUI $recoveryUI,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->manager();
        if ($actor instanceof Response) return $actor;
        $context = self::context($actor);
        $mode = ExperienceMode::Result;
        try { $mode = $this->preferences->workspace($actor, 'cos.operations')['mode']; }
        catch (Throwable) {} // mode is not a security source
        $mode = ExperienceMode::tryFrom((string)$request->query->get('mode', '')) ?? $mode;
        $source = (string)$request->query->get('source', '');
        if (!in_array($source, OperationsSnapshot::SOURCES, true)) $source = '';
        $correlation = substr((string)$request->query->get('correlation', ''), 0, 190);
        $selected = substr((string)$request->query->get('operation', ''), 0, 210);
        $entity = null;
        $key = (string)$request->query->get('entity', '');
        if ($key !== '') {
            try {
                if (strlen($key)>190) throw new \InvalidArgumentException();
                $entity = EntityRef::fromKey($key);
            } catch (\InvalidArgumentException) { return new Response('Invalid entity context.', 400); }
        }
        $cursorValue = (string)$request->query->get('cursor', '');
        try { $cursor = OperationsHistoryCursor::decode($cursorValue); }
        catch (\InvalidArgumentException) { return new Response('Invalid history cursor.', 400); }
        $statusFilter = OperationStatus::tryFrom((string)$request->query->get('status', ''));
        $snapshot = $this->snapshots->read($actor, $entity, $correlation, $source, $selected, $cursor, $statusFilter);
        $detail = $snapshot['detail'];
        $actions = $detail !== null ? $this->decisions->available($actor, $context, $detail) : [];
        $outcome = null; $outcomeUnavailable = false;
        if ($detail !== null && $detail['source'] === 'workflow' && $detail['goal'] !== null) {
            try {
                // Persisted, run-scoped domain contract. No evaluation is triggered by a GET.
                $evaluation = $this->goals->latestTrustedEvaluation($actor, $detail['id']);
                if ($evaluation !== null) {
                    $outcome = ['result'=>$evaluation['result'], 'id'=>$evaluation['evaluation_id'],
                        'recorded'=>$evaluation['recorded_at']];
                }
            } catch (Throwable) { $outcomeUnavailable = true; }
        }
        $recovery = null; $recoveryUnavailable = false; $recoveryControls = [];
        if ($detail !== null && $detail['source'] === 'workflow' && $detail['status']->needsAttention()) {
            try { $recovery = $this->recovery->inspect($actor, $detail);
                if (!$snapshot['detailUnavailable']) $recoveryControls = $this->recoveryUI->available($actor,$context,$detail['id']); }
            catch (Throwable) { $recoveryUnavailable = true; }
        }
        $entityUrl = null;
        if ($detail !== null && $detail['entity'] !== null) {
            try {
                $path = $this->links->resolve($context, $detail['entity'])?->path;
                if (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')
                    && !str_contains($path, '\\') && !preg_match('/[\x00-\x1f\x7f]/', $path)) $entityUrl = $path;
            } catch (Throwable) {}
        }
        return new Response($this->twig->render('experience/operations/index.html.twig', [
            'shell'=>$this->shells->create($actor, $context, 'Operations Center', [new ShellBreadcrumb('Operations Center')],
                aiAvailable: false, connectionState: ShellConnectionState::Stale),
            'snapshot'=>$snapshot, 'mode'=>$mode->value, 'statusFilter'=>$statusFilter?->value ?? '',
            'statuses'=>OperationStatus::cases(), 'source'=>$source, 'sources'=>OperationsSnapshot::SOURCES,
            'entity'=>$entity?->key() ?? '', 'correlation'=>$correlation, 'selected'=>$selected,
            'cursor'=>$cursorValue, 'outcome'=>$outcome, 'outcomeUnavailable'=>$outcomeUnavailable,
            'actions'=>$actions, 'entityUrl'=>$entityUrl,
            'recovery'=>$recovery, 'recoveryUnavailable'=>$recoveryUnavailable, 'recoveryControls'=>$recoveryControls,
            'topic'=>$this->topics->organization($actor->organizationId()->value()),
            'csrfToken'=>$this->csrf->token($request), 'admin'=>$actor->isAdmin() && $actor->allows(TenantPermissions::ADMIN),
        ]), 200, ['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow','Content-Type'=>'text/html; charset=UTF-8']);
    }

    public function decide(Request $request, string $id): Response
    {
        $actor = $this->manager();
        if ($actor instanceof Response) return $actor;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', 403);
        try {
            $row = $this->snapshots->one($actor, 'approval', $id);
            if ($row === null) return new Response('Approval not found.', 404);
            $actionId = (string)$request->request->get('ui_action', '');
            foreach ($this->decisions->available($actor, self::context($actor), $row) as $action) {
                if ($action->id !== $actionId) continue;
                if ($action->confirmationContract() !== null && $request->request->get('confirmed') !== '1') {
                    return new Response('Explicit confirmation required.', 422);
                }
                $this->commands->dispatch(new OperationsMutationCommand(
                    $actor->organizationId()->value(), (int)$actor->userId()->value(),
                    $action->command === 'operations.approve' ? OperationsMutationCommand::APPROVE : OperationsMutationCommand::REJECT,
                    $id, [], CorrelationId::generate()->value(),
                ));
                return new RedirectResponse('/workspace/operations?operation='.rawurlencode('approval:'.$id), 303);
            }
            return new Response('Decision is not available to this operator.', 403);
        } catch (Throwable $e) {
            error_log('cos.operations.decision.unconfirmed '.$e::class);
            return new Response('Decision not confirmed. Refresh the canonical state before retrying.', 409);
        }
    }

    private function manager(): TenantContext|Response
    {
        $actor = $this->tenants->current();
        if ($actor === null) return new RedirectResponse('/auth/login');
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS) || !$actor->allows(TenantPermissions::MANAGE)) return new Response('Forbidden', 403);
        return $actor;
    }

    private static function context(TenantContext $actor): WebExtensionContext
    {
        return new WebExtensionContext($actor->organizationId()->value(), $actor->role()->value(), 'workspace', 'operations', 'operations');
    }
}
