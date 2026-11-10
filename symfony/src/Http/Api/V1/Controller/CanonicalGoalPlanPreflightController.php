<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Pure Goal→Capability planner preflight. No Plan persistence, Approval,
 * Run/Step creation, Domain mutation, Action dispatch, or AI authority.
 */
final readonly class CanonicalGoalPlanPreflightController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,
        private GoalPlanValidator $validator,
    ) {}

    public function validate(Request $request): JsonResponse
    {
        $actor = $this->tenants->current();
        if ($actor === null || !$actor->isManager()
            || !$actor->allows(TenantPermissions::ACCESS)
            || !$actor->allows(TenantPermissions::MANAGE)) {
            return $this->reply(['error'=>'tenant_manager_required'],403);
        }
        if (!$this->csrf->isValid($request)) {
            return $this->reply(['error'=>'invalid_csrf_token'],400);
        }
        $raw = (string)$request->getContent();
        if (strlen($raw) > 65536) return $this->reply(['error'=>'payload_too_large'],413);
        $object = json_decode($raw);
        if (!$object instanceof \stdClass) {
            return $this->reply(['error'=>'invalid_json_object'],422);
        }
        $data = json_decode($raw,true);
        $goalId = $data['goal_id'] ?? null;
        $steps = $data['steps'] ?? null;
        if (!is_string($goalId) || $goalId === '' || strlen($goalId) > 128
            || !is_array($steps) || !array_is_list($steps)
            || count($steps) < 1 || count($steps) > 50) {
            return $this->reply(['error'=>'invalid_plan_proposal'],422);
        }
        try {
            $goal = $this->goals->specification($actor,$goalId);
            if ($goal === null || $goal->ownerId !== $actor->userId()->value()) {
                return $this->reply(['error'=>'goal_not_owned'],403);
            }
            $result = $this->validator->validate($goal,$steps,$this->bindings->available($actor),true);
            return $this->reply([
                'valid'=>$result['valid'],
                'errors'=>$result['errors'],
                'steps'=>$result['steps'],
                'proposal_only'=>true,
                'executed'=>false,
                'persisted'=>false,
                'authorization_granted'=>false,
            ],$result['valid'] ? 200 : 422);
        } catch (DomainException|\InvalidArgumentException $e) {
            return $this->reply(['error'=>'plan_preflight_blocked'],422);
        } catch (Throwable $error) {
            error_log('cos.goal.plan_preflight_failed '.$error->getMessage());
            return $this->reply(['error'=>'plan_preflight_unavailable'],500);
        }
    }

    private function reply(array $payload,int $status):JsonResponse
    {
        $response=new JsonResponse($payload,$status);
        $response->headers->set('Cache-Control','no-store, private');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        return $response;
    }
}
