<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Persistence\Federation\FederationGoalStore;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\ExperienceMode;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

/**
 * P0 Goals draft workspace. Does not dispatch cross-domain work or approve plans.
 */
final readonly class GoalsWorkspaceController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private FederationGoalStore $goals,
        private FederationExperiencePreferenceStore $experience,
        private SessionCsrfValidator $csrf,
        private \App\Persistence\Federation\FederationRunRecoveryService $recovery,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant === null) return self::denied();

        $state = $this->experience->workspace($tenant, 'cos.goals');
        $runs = $this->goals->listRuns($tenant);
        foreach ($runs as &$run) {
            try {
                // A disabled tenant may view its old Run history without
                // implicitly activating Federation execution or recovery.
                $run['recovery'] = $this->recovery->inspect($tenant, (string) $run['run_id']);
            } catch (Throwable) {
                $run['recovery'] = null;
            }
            try {
                $run['outcome'] = $this->goals->latestTrustedEvaluation($tenant, (string) $run['run_id']);
            } catch (Throwable) {
                // A broken evaluation is never silently treated as success.
                $run['outcome'] = null;
                $run['outcome_unavailable'] = true;
            }
        }
        unset($run);
        return new Response(
            $this->twig->render('experience/federation/goals.html.twig', [
                'goals' => $this->goals->listGoals($tenant),
                'runs' => $runs,
                'reconciled' => $request->query->getBoolean('reconciled'),
                'evaluated' => $request->query->getBoolean('evaluated'),
                'outcomeError' => $request->query->get('outcome_error') === 'unavailable',
                'mode' => $state['mode']->value,
                'csrfToken' => $this->csrf->token($request),
                'created' => $request->query->getBoolean('created'),
                'error' => $request->query->get('error', '') === 'invalid_input',
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    /**
     * Operator-triggered receipt-only recovery from the Goals workspace.
     * The POST never submits an Action or advances an unapproved Step.
     */
    public function reconcileRun(Request $request, string $runId): Response
    {
        $actor = $this->manager();
        if ($actor === null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', 400);

        try {
            $this->recovery->reconcileVerified($actor, $runId);
            return new RedirectResponse('/workspace/goals?reconciled=1', 303);
        } catch (DomainException|\LogicException) {
            return new Response('Federation Run recovery unavailable.', 422);
        } catch (Throwable) {
            return new Response('Federation Run recovery failed.', 500);
        }
    }

    /**
     * Explicit manager operation only. Calls the trusted Domain read model,
     * does not submit/replay an Action and never accepts supplied metrics.
     */
    public function evaluateRun(Request $request, string $runId): Response
    {
        $actor = $this->manager();
        if ($actor === null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', 400);

        try {
            $this->goals->recordEvaluation($actor, 'eval-' . bin2hex(random_bytes(12)), $runId);
            return new RedirectResponse('/workspace/goals?evaluated=1', 303);
        } catch (DomainException|\LogicException|\InvalidArgumentException) {
            return new RedirectResponse('/workspace/goals?outcome_error=unavailable', 303);
        } catch (Throwable) {
            return new Response('Trusted Goal evaluation unavailable.', 500);
        }
    }

    public function selectMode(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant === null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', 400);

        $mode = ExperienceMode::tryFrom((string) $request->request->get('mode', ''));
        if ($mode === null) return new Response('Unsupported Experience mode.', 422);
        $this->experience->saveWorkspace($tenant, 'cos.goals', '', $mode, []);
        return new RedirectResponse('/workspace/goals', 303);
    }

    public function create(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant === null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', 400);

        $desired = trim((string) $request->request->get('desired_result', ''));
        $criterion = trim((string) $request->request->get('criterion', 'qualified_leads'));
        $rawTarget = $request->request->get('target', '');
        $target = is_string($rawTarget) && ctype_digit($rawTarget) ? (int) $rawTarget : 0;
        if ($desired === '' || mb_strlen($desired) > 1200
            || !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $criterion)
            || $target < 1 || $target > 1000000) {
            return new RedirectResponse('/workspace/goals?error=invalid_input', 303);
        }

        try {
            $goal = new GoalSpecification(
                goalId: 'goal-' . bin2hex(random_bytes(12)),
                organizationId: $tenant->organizationId()->value(),
                ownerId: $tenant->userId()->value(),
                desiredResult: $desired,
                criteria: [['id' => $criterion, 'operator' => 'at_least', 'expected' => $target]],
                allowedCapabilities: [],
            );
            $this->goals->createGoal($tenant, $goal);
            return new RedirectResponse('/workspace/goals?created=1', 303);
        } catch (DomainException) {
            return self::denied();
        } catch (Throwable) {
            return new Response('Unable to create Goal.', 500);
        }
    }

    private function manager(): ?TenantContext
    {
        $tenant = $this->tenants->current();
        return $tenant !== null && $tenant->isManager() && $tenant->allows(TenantPermissions::MANAGE)
            ? $tenant : null;
    }

    private static function denied(): Response
    {
        return new Response('Manager authorization required.', 403);
    }
}
