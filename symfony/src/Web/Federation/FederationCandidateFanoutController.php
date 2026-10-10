<?php
declare(strict_types=1);

namespace App\Web\Federation;

use App\Persistence\Federation\FederationCandidateFanoutService;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Explicit manager UX/API for read-only fan-out preview and draft creation.
 * Not an Action dispatcher, worker, Approval endpoint or automated campaign.
 */
final readonly class FederationCandidateFanoutController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private FederationCandidateFanoutService $fanout,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $actor = $this->manager();
        if ($actor === null) return self::respond(['error'=>'forbidden'],403);
        $parameters = $this->inputs($request, false);
        if ($parameters === null) return self::respond(['error'=>'invalid_fanout_input'],422);
        try {
            $result = $this->fanout->preview($actor,...$parameters);
            // Preview is not authorization to execute external Actions.
            return self::respond($result);
        } catch (DomainException|\InvalidArgumentException) {
            return self::respond(['error'=>'fanout_source_or_candidate_unavailable'],422);
        } catch (Throwable) {
            return self::respond(['error'=>'unable_to_preview_fanout'],500);
        }
    }

    public function propose(Request $request): JsonResponse
    {
        $actor = $this->manager();
        if ($actor === null) return self::respond(['error'=>'forbidden'],403);
        if (!$this->csrf->isValid($request)) return self::respond(['error'=>'csrf'],400);
        $parameters = $this->inputs($request, true);
        if ($parameters === null) return self::respond(['error'=>'invalid_fanout_input'],422);
        try {
            return self::respond($this->fanout->propose($actor,...$parameters),201);
        } catch (DomainException|\InvalidArgumentException|\LogicException) {
            return self::respond(['error'=>'fanout_not_proposable_or_conflicting'],422);
        } catch (Throwable) {
            return self::respond(['error'=>'unable_to_propose_fanout'],500);
        }
    }

    /** @return array{string,string,string,int,array<string,mixed>}|null */
    private function inputs(Request $request, bool $post): ?array
    {
        $bag = $post ? $request->request : $request->query;
        $fields = [];
        foreach ([
            'goal_id'=>64,'source_run_id'=>64,'source_step_id'=>64,
            'policy_id'=>80,'template_id'=>80,'expected_value'=>500,
            'recommended_play'=>191,'recommended_action'=>1000,
        ] as $key=>$max) {
            $value = $bag->get($key);
            if (!is_string($value) || trim($value)==='' || mb_strlen($value)>$max) return null;
            $fields[$key] = trim($value);
        }
        $requested = $bag->get('requested');
        $revision = $bag->get('policy_revision');
        if (!is_string($requested) || !ctype_digit($requested)
            || !is_string($revision) || !ctype_digit($revision)) return null;
        $count=(int)$requested;
        $version=(int)$revision;
        if ($count<1 || $count>50 || $version<1 || $version>1000000) return null;
        return [
            $fields['goal_id'], $fields['source_run_id'], $fields['source_step_id'],
            $count,
            [
                'policy_id'=>$fields['policy_id'], 'policy_revision'=>$version,
                'template_id'=>$fields['template_id'],
                'expected_value'=>$fields['expected_value'],
                'recommended_play'=>$fields['recommended_play'],
                'recommended_action'=>$fields['recommended_action'],
            ],
        ];
    }

    private function manager(): ?TenantContext
    {
        $actor=$this->tenants->current();
        return $actor !== null && $actor->isManager() && $actor->allows(TenantPermissions::MANAGE)
            ? $actor : null;
    }

    /** @param array<string,mixed> $payload */
    private static function respond(array $payload,int $status=200):JsonResponse
    {
        $response=new JsonResponse($payload,$status);
        $response->headers->set('Cache-Control','no-store, private');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        return $response;
    }
}
