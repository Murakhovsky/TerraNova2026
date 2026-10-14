<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationDiscoveryPlanProposalService;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Human-initiated parent discovery Plan creation, NOT discovery execution.
 * Existing ActionPolicy/Approval machinery remains the only approval route.
 */
final readonly class FederationDiscoveryPlanController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private FederationDiscoveryPlanProposalService $proposals,
    ) {}

    public function propose(Request $request): JsonResponse
    {
        $actor = $this->tenants->current();
        if ($actor === null || !$actor->isManager()
            || !$actor->allows(TenantPermissions::MANAGE)) {
            return self::reply(['error' => 'forbidden'], 403);
        }
        if (!$this->csrf->isValid($request)) {
            return self::reply(['error' => 'csrf'], 400);
        }
        $goalId = $request->request->get('goal_id');
        $universeId = $request->request->get('universe_id');
        $rawSlot = $request->request->get('slot');
        $rawLimit = $request->request->get('limit');
        if (!is_string($goalId) || !is_string($universeId)
            || !is_string($rawSlot) || !ctype_digit($rawSlot)
            || !is_string($rawLimit) || !ctype_digit($rawLimit)
            || mb_strlen($universeId) > 80) {
            return self::reply(['error' => 'invalid_discovery_plan_input'], 422);
        }
        try {
            return self::reply($this->proposals->propose(
                $actor, $goalId, $universeId, (int)$rawSlot, (int)$rawLimit,
            ), 201);
        } catch (DomainException|\InvalidArgumentException|\LogicException) {
            return self::reply(['error' => 'discovery_plan_not_proposable'], 422);
        } catch (Throwable) {
            // A concurrent repeat cannot silently mint a second Plan.
            return self::reply(['error' => 'discovery_plan_unavailable_or_existing'], 409);
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
