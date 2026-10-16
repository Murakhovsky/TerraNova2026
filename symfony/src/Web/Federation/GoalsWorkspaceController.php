<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationSmallGoalPlanProposalService;
use App\Persistence\Federation\FederationDiscoveryPlanProposalService;
use App\Persistence\Federation\FederationPlanApprovalCoordinator;
use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use Platform\Orchestration\Goal\FederationSmallTaskPlanFactory;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\ExperienceMode;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\FederationGolden50GoalProfile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

/**
 * Tenant-scoped Goals workspace. All execution delegates to canonical Platform Workflow.
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
        private FederationSmallGoalPlanProposalService $smallPlans,
        private FederationDiscoveryPlanProposalService $discoveryPlans,
        private FederationPlanApprovalCoordinator $planApprovals,
        private FederationCanonicalWorkflowBridge $canonical,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant === null) return self::denied();

        $state = $this->experience->workspace($tenant, 'cos.goals');
        $runs = $this->goals->listRuns($tenant);
        $plansByGoal = [];
        foreach ($this->goals->listGoalPlans($tenant) as $plan) {
            $plansByGoal[(string)$plan['goal_id']][] = $plan;
        }
        $runsByGoal = [];
        foreach ($runs as &$run) {
            // Storage timestamps are UTC. Convert only presentation values:
            // the database and Domain metrics remain timezone-independent.
            $run['created_at_kyiv'] = FederationKyivTime::displayUtc((string) $run['created_at']);
            $run['canonical'] = false;
            $run['steps'] = [];
            $run['progress_error'] = false;
            try {
                if ($this->canonical->owns($tenant, (string)$run['run_id'])) {
                    $run['canonical'] = true;
                    $status = $this->canonical->status($tenant, (string)$run['run_id']);
                    $run['steps'] = $status['steps'];
                    $run['recovery'] = null; // legacy recovery may never touch canonical Steps
                } else {
                    $legacy = $this->goals->run($tenant, (string)$run['run_id']);
                    $run['steps'] = array_map(static fn(array $step):array => [
                        'step_id'=>$step['step_id'], 'capability_id'=>$step['capability_id'],
                        'state'=>$step['state'],'receipt'=>$step['result_reference'],
                    ], $legacy['steps'] ?? []);
                    $run['recovery'] = $this->recovery->inspect($tenant, (string)$run['run_id']);
                }
            } catch (Throwable) {
                $run['recovery'] = null;
                $run['progress_error'] = true;
            }
            $run['total_steps'] = count($run['steps']);
            $run['completed_steps'] = count(array_filter($run['steps'],
                static fn(array $step):bool => ($step['state']??null)==='completed'));
            $run['approval'] = $this->goals->planApprovalAction($tenant, (string)$run['plan_id']);
            try {
                $run['outcome'] = $this->goals->latestTrustedEvaluation($tenant, (string) $run['run_id']);
                if ($run['outcome'] !== null) {
                    $run['outcome']['recorded_at_kyiv'] = FederationKyivTime::displayUtc(
                        (string) $run['outcome']['recorded_at'],
                    );
                    foreach ($run['outcome']['criteria'] as &$criterion) {
                        foreach (['window_start', 'window_end'] as $field) {
                            if (is_string($criterion[$field] ?? null) && $criterion[$field] !== '') {
                                $criterion[$field . '_kyiv'] = FederationKyivTime::displayUtc($criterion[$field]);
                            }
                        }
                    }
                    unset($criterion);
                }
            } catch (Throwable) {
                // A broken evaluation is never silently treated as success.
                $run['outcome'] = null;
                $run['outcome_unavailable'] = true;
            }
            $runsByGoal[(string)$run['goal_id']][] = $run;
        }
        unset($run);
        return new Response(
            $this->twig->render('experience/federation/goals.html.twig', [
                'goals' => $this->goals->listGoals($tenant),
                'runs' => $runs,
                'plansByGoal' => $plansByGoal,
                'runsByGoal' => $runsByGoal,
                'canonicalRolloutEnabled' => $this->canonical->newRunsEnabled($tenant),
                'notice' => (string)$request->query->get('notice', ''),
                'blocker' => (string)$request->query->get('blocker', ''),
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
        $criterion = trim((string) $request->request->get('criterion', 'sales.won_deals'));
        $rawTarget = $request->request->get('target', '');
        $target = is_string($rawTarget) && ctype_digit($rawTarget) ? (int) $rawTarget : 0;
        if ($desired === '' || mb_strlen($desired) > 1200
            || !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $criterion)
            || $target < 1 || $target > 1000000) {
            return new RedirectResponse('/workspace/goals?error=invalid_input', 303);
        }

        try {
            $allowedCapabilities = FederationGolden50GoalProfile::capabilities($criterion,$target);
            if (in_array($criterion, FederationSmallTaskPlanFactory::CRITERIA, true)) {
                $allowedCapabilities = [FederationSmallTaskPlanFactory::CAPABILITY];
            }
        } catch (DomainException) {
            return new RedirectResponse('/workspace/goals?error=invalid_input',303);
        }

        try {
            $goal = new GoalSpecification(
                goalId: 'goal-' . bin2hex(random_bytes(12)),
                organizationId: $tenant->organizationId()->value(),
                ownerId: $tenant->userId()->value(),
                desiredResult: $desired,
                criteria: [['id' => $criterion, 'operator' => 'at_least', 'expected' => $target]],
                allowedCapabilities: $allowedCapabilities,
            );
            $this->goals->createGoal($tenant, $goal);
            return new RedirectResponse('/workspace/goals?created=1', 303);
        } catch (DomainException) {
            return self::denied();
        } catch (Throwable) {
            return new Response('Unable to create Goal.', 500);
        }
    }

    /**
     * Explicitly build an immutable typed Plan for a SAVED Goal. Golden 50
     * retains its verified discovery route; Sales goals allow 1..3 tasks.
     */
    public function buildPlan(Request $request, string $goalId): Response
    {
        $actor=$this->manager();
        if ($actor===null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',400);
        try {
            $goal=$this->goals->specification($actor,$goalId);
            if ($goal===null || $goal->ownerId!==$actor->userId()->value()) {
                throw new DomainException('Only the Goal owner can propose its plan.');
            }
            $kind=(string)$request->request->get('scenario','');
            if ($kind==='golden-discovery') {
                if (($goal->criteria[0]['id']??null)!==FederationGolden50GoalProfile::METRIC
                    || ($goal->criteria[0]['expected']??null)!==50) {
                    throw new DomainException('This Goal is not the Golden 50 reference scenario.');
                }
                $universe=trim((string)$request->request->get('universe_id',''));
                $slot=(string)$request->request->get('slot','');
                $limit=(string)$request->request->get('limit','');
                if (!ctype_digit($slot) || !ctype_digit($limit)) {
                    throw new DomainException('Discovery slot and limit must be positive integers.');
                }
                $this->discoveryPlans->propose($actor,$goalId,$universe,(int)$slot,(int)$limit);
            } elseif ($kind==='small-tasks') {
                $count=(string)$request->request->get('task_count','');
                if (!in_array($count,['1','2','3'],true)) {
                    throw new DomainException('Choose one, two or three operations.');
                }
                $tasks=[];
                for ($i=1;$i<=(int)$count;$i++) {
                    $minutes=(string)$request->request->get('minutes_'.$i,'');
                    if (!ctype_digit($minutes)) throw new DomainException('A task deadline is invalid.');
                    $tasks[]=[
                        'deal_id'=>trim((string)$request->request->get('deal_'.$i,'')),
                        'title'=>trim((string)$request->request->get('title_'.$i,'')),
                        'due_in_minutes'=>(int)$minutes,
                    ];
                }
                $this->smallPlans->propose($actor,$goalId,$tasks);
            } else {
                throw new DomainException('No executable scenario was selected.');
            }
            return self::back('notice','plan_proposed');
        } catch (DomainException|\InvalidArgumentException|\LogicException $e) {
            return self::back('blocker',self::reason($e));
        } catch (Throwable) {
            return self::back('blocker','storage_unavailable');
        }
    }

    /** Starts existing Plan approval process; never self-approves. */
    public function requestApproval(Request $request,string $planId): Response
    {
        $actor=$this->manager();
        if ($actor===null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',400);
        try {
            $this->planApprovals->requestApproval(
                $actor,$planId,'fed-ui-'.bin2hex(random_bytes(12)),
            );
            return self::back('notice','approval_requested');
        } catch (DomainException|\InvalidArgumentException|\LogicException $e) {
            return self::back('blocker',self::reason($e));
        } catch (Throwable) {
            return self::back('blocker','approval_unavailable');
        }
    }

    /** Starts ONE canonical technical Run after independent human Plan approval. */
    public function startPlan(Request $request,string $planId): Response
    {
        $actor=$this->manager();
        if ($actor===null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',400);
        try {
            if (!$this->canonical->newRunsEnabled($actor)) {
                throw new DomainException('Canonical Federation new-run rollout is not enabled for this tenant.');
            }
            $approval=$this->goals->planApprovalAction($actor,$planId);
            if (($approval['status']??null)!=='COMPLETED') {
                throw new DomainException('Plan is waiting for an independent human approval and Action receipt.');
            }
            $runId=$this->canonical->plannedRunId($actor,$planId) ?? 'run-'.bin2hex(random_bytes(12));
            $this->canonical->start($actor,$runId,$planId,(string)$approval['id']);
            return self::back('notice','run_started');
        } catch (DomainException|\InvalidArgumentException|\LogicException $e) {
            return self::back('blocker',self::reason($e));
        } catch (Throwable) {
            return self::back('blocker','start_unavailable');
        }
    }

    /** Operator advances the authoritative Workflow, not a Federation executor. */
    public function advanceRun(Request $request,string $runId): Response
    {
        $actor=$this->manager();
        if ($actor===null) return self::denied();
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',400);
        try {
            $run=$this->canonical->status($actor,$runId);
            $approval=$this->goals->planApprovalAction($actor,(string)$run['plan_id']);
            if (($approval['status']??null)!=='COMPLETED') {
                throw new DomainException('The original Plan approval Action is not complete.');
            }
            $result=$this->canonical->advance($actor,$runId,(string)$approval['id']);
            $state=(string)($result['state']??'');
            if ($state === 'manual_reconciliation_required') {
                return self::back('blocker','reconcile_required');
            }
            if ($state === 'awaiting_human_approval') {
                return self::back('blocker','step_approval_waiting');
            }
            if ($state === 'awaiting_action') {
                return self::back('notice','action_queued');
            }
            if ($state === 'awaiting_domain_evidence') {
                return self::back('notice','evidence_pending');
            }
            return self::back('notice','run_advanced');
        } catch (DomainException|\InvalidArgumentException|\LogicException $e) {
            return self::back('blocker',self::reason($e));
        } catch (Throwable) {
            return self::back('blocker','advance_unavailable');
        }
    }

    private static function back(string $kind,string $code): RedirectResponse
    {
        return new RedirectResponse('/workspace/goals?'.$kind.'='.rawurlencode($code),303);
    }

    /** Fixed UI messages, not exception text, SQL, or untrusted JSON. */
    private static function reason(\Throwable $e): string
    {
        $s=strtolower($e->getMessage());
        return match(true) {
            str_contains($s,'rollout') => 'rollout_disabled',
            str_contains($s,'already has an execution') ||
              str_contains($s,'duplicate dispatch') => 'already_started',
            str_contains($s,'already has plans') => 'spec_frozen',
            str_contains($s,'not found') || str_contains($s,'not present')
              || str_contains($s,'unavailable in tenant') => 'not_found',
            str_contains($s,'approval_required') || str_contains($s,'policy') => 'approval_policy',
            str_contains($s,'human approval') || str_contains($s,'approval action')
              || str_contains($s,'approval reference') => 'waiting_approval',
            str_contains($s,'universe') => 'universe_unavailable',
            str_contains($s,'capability') || str_contains($s,'module')
              || str_contains($s,'handler') => 'capability_unavailable',
            str_contains($s,'task') || str_contains($s,'small scenario')
              || str_contains($s,'operation') || str_contains($s,'slot')
              || str_contains($s,'limit') => 'invalid_steps',
            str_contains($s,'owner') => 'owner_required',
            str_contains($s,'stale') => 'stale_execution',
            default => 'plan_blocked',
        };
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
