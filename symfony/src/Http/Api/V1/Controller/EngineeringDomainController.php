<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Engineering\Application\DomainDevelopment\EngineeringDomainRuntimeService;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Security\SessionCsrfValidator;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class EngineeringDomainController
{
    public function __construct(
        private EngineeringDomainRuntimeService $runtime,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        $tenant = $this->tenants->current();

        try {
            return new JsonResponse(['ok' => true, 'data' => $this->runtime->list(
                $tenant->organizationId()->value(),
                max(1, min(100, (int) $request->query->get('limit', 50))),
            )]);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function create(Request $request): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        $tenant = $this->tenants->current();
        $input = $this->input($request);

        $domainKey = trim((string) ($input['domain_key'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $specification = trim((string) ($input['master_specification'] ?? ''));
        $repository = trim((string) ($input['target_repository'] ?? ''));
        if ($domainKey === '' || $name === '' || $specification === '') {
            return $this->error(
                'domain_input_required',
                'domain_key, name and master_specification are required.',
                422,
            );
        }

        try {
            $id = $this->runtime->create(
                organizationId: $tenant->organizationId()->value(),
                domainKey: $domainKey,
                name: $name,
                masterSpecification: $specification,
                targetRepository: $repository,
                targetBranch: trim((string) ($input['target_branch'] ?? '')),
                createdBy: 'user:'.$tenant->userId()->value(),
                maxParallelFeatures: max(0, min(20, (int) ($input['max_parallel_features'] ?? 0))),
                maxParallelDevelopers: max(0, min(20, (int) ($input['max_parallel_developers'] ?? 0))),
                maxParallelReviews: max(0, min(20, (int) ($input['max_parallel_reviews'] ?? 0))),
                maxParallelQa: max(0, min(20, (int) ($input['max_parallel_qa'] ?? 0))),
                maxFeatureRetries: max(-1, min(50, (int) ($input['max_feature_retries'] ?? -1))),
                maxDomainIntegrationCycles: max(0, min(20, (int) ($input['max_domain_integration_cycles'] ?? 0))),
                contextBudget: max(0, (int) ($input['context_budget'] ?? 0)),
                tokenBudget: max(0, (int) ($input['token_budget'] ?? 0)),
                costBudget: isset($input['cost_budget']) ? max(0.0, (float) $input['cost_budget']) : -1.0,
            );

            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->view($id, $tenant->organizationId()->value()),
            ], 201);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function get(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->view(
                    EngineeringId::assert($id),
                    $tenant->organizationId()->value(),
                ),
            ]);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function plan(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            $domainId = EngineeringId::assert($id);
            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->plan(
                    $domainId,
                    $tenant->organizationId()->value(),
                    $this->correlationId($request, 'plan', $domainId),
                ),
            ], 202);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function tick(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            $domainId = EngineeringId::assert($id);
            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->tick(
                    $domainId,
                    $tenant->organizationId()->value(),
                    $this->correlationId($request, 'tick', $domainId),
                ),
            ], 202);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            $domainId = EngineeringId::assert($id);
            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->verify(
                    $domainId,
                    $tenant->organizationId()->value(),
                    $this->correlationId($request, 'verify', $domainId),
                ),
            ], 202);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function humanDecision(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            $input = $this->input($request);
            $decisionId = trim((string) ($input['decision_id'] ?? ''));
            $selectedOption = trim((string) ($input['selected_option'] ?? ''));
            if ($decisionId === '' || $selectedOption === '') {
                return $this->error('domain_decision_input_required', 'decision_id and selected_option are required.', 422);
            }

            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->answerHumanDecision(
                    EngineeringId::assert($id),
                    $tenant->organizationId()->value(),
                    EngineeringId::assert($decisionId),
                    $selectedOption,
                    'user:'.$tenant->userId()->value(),
                    isset($input['notes']) ? (string) $input['notes'] : null,
                ),
            ]);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function featureFlags(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            $input = $this->input($request);
            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->updateFeatureFlags(
                    EngineeringId::assert($id),
                    $tenant->organizationId()->value(),
                    $input,
                    'user:'.$tenant->userId()->value(),
                ),
            ]);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            return new JsonResponse([
                'ok' => true,
                'data' => $this->runtime->approveRelease(
                    EngineeringId::assert($id),
                    $tenant->organizationId()->value(),
                    'user:'.$tenant->userId()->value(),
                ),
            ]);
        } catch (Throwable $error) {
            return $this->exception($error);
        }
    }

    private function authorize(Request $request, bool $mutation): ?JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->isManager() || !$tenant->allows(TenantPermissions::MANAGE)) {
            return $this->error('manager_required', 'Manager authorization required.', 403);
        }
        if ($mutation && !$this->csrf->isValid($request)) {
            return $this->error('invalid_csrf_token', 'Invalid CSRF token.', 400);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : $request->request->all();
    }

    private function correlationId(Request $request, string $action, string $domainId): string
    {
        $existing = $request->attributes->get('_cos_correlation_id');
        if ($existing instanceof CorrelationId) return $existing->value();
        return 'engineering-domain:'.$action.':'.$domainId.':'.EngineeringId::generate();
    }

    private function exception(Throwable $error): JsonResponse
    {
        $message = $error->getMessage();
        $lower = strtolower($message);
        $status = str_contains($lower, 'not found') ? 404
            : (str_contains($lower, 'cannot') || str_contains($lower, 'only') || str_contains($lower, 'current status') ? 409 : 422);
        return $this->error('engineering_domain_request_failed', $message, $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
