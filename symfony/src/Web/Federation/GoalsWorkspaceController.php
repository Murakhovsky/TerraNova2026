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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant === null) return self::denied();

        $state = $this->experience->workspace($tenant, 'cos.goals');
        return new Response(
            $this->twig->render('experience/federation/goals.html.twig', [
                'goals' => $this->goals->listGoals($tenant),
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
