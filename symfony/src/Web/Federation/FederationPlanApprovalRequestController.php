<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationPlanApprovalCoordinator;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Exposes ONLY canonical human Plan approval request (parent and children).
 * Does not approve a Plan, fabricate an approver, or run any Action.
 */
final readonly class FederationPlanApprovalRequestController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private FederationPlanApprovalCoordinator $approvals,
    ) {}

    public function request(Request $request, string $planId): JsonResponse
    {
        $actor = $this->tenants->current();
        if ($actor === null || !$actor->isManager()
            || !$actor->allows(TenantPermissions::MANAGE)) {
            return self::reply(['error' => 'forbidden'], 403);
        }
        if (!$this->csrf->isValid($request)) {
            return self::reply(['error' => 'csrf'], 400);
        }
        try {
            $approval = $this->approvals->requestApproval(
                $actor, $planId, 'federation-plan-request-' . bin2hex(random_bytes(12)),
            );
            return self::reply([
                'plan_id' => $planId,
                'approval_action_id' => $approval['action_id'],
                'approval_status' => $approval['status'],
                'plan_approved' => false,
                'requires_independent_human_approval' => true,
            ], 202);
        } catch (DomainException|\InvalidArgumentException|\LogicException) {
            return self::reply(['error' => 'plan_approval_not_requestable'], 422);
        } catch (Throwable) {
            return self::reply(['error' => 'plan_approval_request_failed'], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private static function reply(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
