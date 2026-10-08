<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationSequentialOrchestrator;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Authenticated, CSRF-guarded, explicitly triggered Federation Run operations.
 * Does not allow free-form step arguments, silent approvals or automatic replay.
 */
final readonly class FederationRunController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private FederationGoalStore $goals,
        private FederationSequentialOrchestrator $orchestrator,
    ) {}

    public function status(string $runId): JsonResponse
    {
        $actor = $this->manager();
        if ($actor === null) return self::reply(['error' => 'forbidden'], 403);
        try {
            $run = $this->goals->run($actor, $runId);
            if ($run === null) return self::reply(['error' => 'not_found'], 404);
            return self::reply([
                'run_id' => $runId, 'goal_id' => $run['goal_id'],
                'plan_id' => $run['plan_id'], 'state' => $run['state'],
                'revision' => (int) $run['revision'],
                'steps' => array_map(static fn (array $step): array => [
                    'step_id' => $step['step_id'],
                    'capability_id' => $step['capability_id'],
                    'state' => $step['state'], 'attempts' => (int) $step['attempts'],
                    'receipt' => $step['result_reference'],
                ], $run['steps']),
            ]);
        } catch (\InvalidArgumentException) {
            return self::reply(['error' => 'invalid_run'], 422);
        }
    }

    public function start(Request $request): JsonResponse
    {
        $actor = $this->manager();
        if ($actor === null) return self::reply(['error' => 'forbidden'], 403);
        if (!$this->csrf->isValid($request)) return self::reply(['error' => 'csrf'], 400);
        try {
            return self::reply($this->orchestrator->start(
                $actor,
                'run-' . bin2hex(random_bytes(12)),
                (string) $request->request->get('plan_id', ''),
                (string) $request->request->get('approval_action_id', ''),
            ), 201);
        } catch (DomainException|\InvalidArgumentException) {
            return self::reply(['error' => 'plan_not_executable'], 422);
        } catch (Throwable) {
            return self::reply(['error' => 'unable_to_start_run'], 500);
        }
    }

    public function advance(Request $request, string $runId): JsonResponse
    {
        $actor = $this->manager();
        if ($actor === null) return self::reply(['error' => 'forbidden'], 403);
        if (!$this->csrf->isValid($request)) return self::reply(['error' => 'csrf'], 400);
        try {
            return self::reply($this->orchestrator->advance(
                $actor, $runId, (string) $request->request->get('approval_action_id', ''),
            ));
        } catch (DomainException|\InvalidArgumentException|\LogicException) {
            return self::reply(['error' => 'run_not_executable_or_stale'], 422);
        } catch (Throwable) {
            return self::reply(['error' => 'unable_to_advance_run'], 500);
        }
    }

    private function manager(): ?TenantContext
    {
        $tenant = $this->tenants->current();
        return $tenant !== null && $tenant->isManager() && $tenant->allows(TenantPermissions::MANAGE)
            ? $tenant : null;
    }

    /** @param array<string,mixed> $payload */
    private static function reply(array $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
