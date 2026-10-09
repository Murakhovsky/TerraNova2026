<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationGoalOutcomeAggregationService;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/** Read-only, manager-scoped native proof inspection. No Action execution. */
final readonly class FederationGoalAggregateController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private FederationGoalOutcomeAggregationService $aggregates,
    ) {}

    public function show(string $goalId): JsonResponse
    {
        $actor=$this->tenants->current();
        if ($actor===null || !$actor->isManager()
            || !$actor->allows(TenantPermissions::MANAGE)) {
            return self::reply(['error'=>'forbidden'],403);
        }
        try {
            return self::reply($this->aggregates->inspect($actor,$goalId));
        } catch (DomainException|\InvalidArgumentException) {
            return self::reply(['error'=>'aggregate_not_verifiable'],422);
        } catch (Throwable) {
            return self::reply(['error'=>'aggregate_unavailable'],500);
        }
    }

    /** @param array<string,mixed> $payload */
    private static function reply(array $payload,int $status=200): JsonResponse
    {
        $response=new JsonResponse($payload,$status);
        $response->headers->set('Cache-Control','no-store, private');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        return $response;
    }
}
