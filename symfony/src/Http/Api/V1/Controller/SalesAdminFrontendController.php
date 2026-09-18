<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Sales\Admin\SalesAdminAuthorization;
use App\Application\Sales\Admin\SalesAdminMutationCommand;
use App\Application\Sales\Admin\SalesAdminQuery;
use App\Security\LegacySessionCsrfValidator;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

final readonly class SalesAdminFrontendController
{
    public function __construct(
        private CommandBusInterface $commands,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
        private SalesAdminAuthorization $authorization,
    ) {
    }

    public function pipelines(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::PIPELINES, 'pipeline.list'); }
    public function pipeline(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::PIPELINES, 'pipeline.view', $id); }
    public function pipelineValidate(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::PIPELINES, 'pipeline.validate', $id); }
    public function pipelineLostReasons(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::PIPELINES, 'pipeline.lost_reasons', $id); }
    public function pipelineRevisions(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::PIPELINES, 'pipeline.revisions', $id); }

    public function createPipeline(Request $request): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.create', null, 201); }
    public function updatePipeline(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.update', $id); }
    public function clonePipeline(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.clone', $id, 201); }
    public function createStage(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.stage.create', $id, 201); }
    public function updateStage(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.stage.update', $id); }
    public function reorderStages(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.reorder', $id); }
    public function transitions(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.transitions', $id); }
    public function createLostReason(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.lost_reason.create', $id, 201); }
    public function updateLostReason(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::PIPELINES, 'pipeline.lost_reason.update', $id); }

    public function ruleCatalog(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::RULES, 'rule.catalog'); }
    public function rules(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::RULES, 'rule.list'); }
    public function rule(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::RULES, 'rule.view', $id); }
    public function ruleDryRun(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::RULES, 'rule.dry_run', $id); }
    public function ruleRevisions(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::RULES, 'rule.revisions', $id); }
    public function createRule(Request $request): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::RULES, 'rule.create', null, 201); }
    public function updateRule(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::RULES, 'rule.update', $id); }
    public function activateRule(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::RULES, 'rule.activate', $id); }
    public function disableRule(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::RULES, 'rule.disable', $id); }
    public function archiveRule(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::RULES, 'rule.archive', $id); }
    public function restoreRule(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::RULES, 'rule.restore-system', $id); }

    public function agentCatalog(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::AGENTS, 'agent.catalog'); }
    public function agents(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::AGENTS, 'agent.list'); }
    public function agent(Request $request, string $name): JsonResponse { return $this->query($request, SalesAdminAuthorization::AGENTS, 'agent.view', $name); }
    public function agentRevisions(Request $request, string $name): JsonResponse { return $this->query($request, SalesAdminAuthorization::AGENTS, 'agent.revisions', $name); }
    public function updateAgent(Request $request, string $name): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::AGENTS, 'agent.update', $name); }
    public function testAgent(Request $request, string $name): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::AGENTS, 'agent.test', $name); }

    public function policyCatalog(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::POLICIES, 'policy.catalog'); }
    public function policyActions(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::POLICIES, 'policy.actions'); }
    public function policyRevisions(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::POLICIES, 'policy.revisions', $id); }
    public function createPolicy(Request $request): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::POLICIES, 'policy.create', null, 201); }
    public function updatePolicy(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::POLICIES, 'policy.update', $id); }
    public function archivePolicy(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::POLICIES, 'policy.archive', $id); }
    public function previewPolicy(Request $request): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::POLICIES, 'policy.preview'); }

    public function teamCatalog(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::TEAMS, 'team.catalog'); }
    public function teamUsers(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::TEAMS, 'team.users'); }
    public function teams(Request $request): JsonResponse { return $this->query($request, SalesAdminAuthorization::TEAMS, 'team.list'); }
    public function team(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::TEAMS, 'team.view', $id); }
    public function teamRevisions(Request $request, string $id): JsonResponse { return $this->query($request, SalesAdminAuthorization::TEAMS, 'team.revisions', $id); }
    public function createTeam(Request $request): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::TEAMS, 'team.create', null, 201); }
    public function updateTeam(Request $request, string $id): JsonResponse { return $this->mutate($request, SalesAdminAuthorization::TEAMS, 'team.update', $id); }

    public function teamMember(Request $request, string $id, string $userId): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesAdminAuthorization::TEAMS,
            'team.member',
            $id,
            200,
            ['user_id' => (int) $userId],
        );
    }

    public function userCapabilities(Request $request, string $userId): JsonResponse
    {
        return $this->mutate($request, SalesAdminAuthorization::TEAMS, 'team.capabilities', $userId);
    }

    private function query(
        Request $request,
        string $capability,
        string $operation,
        ?string $resourceId = null,
    ): JsonResponse {
        $tenant = $this->context(null, $capability, false);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        try {
            return $this->ok($this->queries->ask(new SalesAdminQuery(
                $tenant->organizationId(),
                $operation,
                $resourceId,
                ['limit' => (int) $request->query->get('limit', 100)],
            )));
        } catch (Throwable $error) {
            return $this->failure($error);
        }
    }

    /** @param array<string,mixed> $extra */
    private function mutate(
        Request $request,
        string $capability,
        string $operation,
        ?string $resourceId = null,
        int $status = 200,
        array $extra = [],
    ): JsonResponse {
        $tenant = $this->context($request, $capability, true);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        try {
            $data = $this->commands->dispatch(new SalesAdminMutationCommand(
                $tenant->organizationId(),
                (int) $tenant->userId()->value(),
                $operation,
                $resourceId,
                [...$this->input($request), ...$extra],
                $this->correlationId($request),
            ));

            return $this->ok($data, $status);
        } catch (Throwable $error) {
            return $this->failure($error);
        }
    }

    private function context(?Request $request, string $capability, bool $mutation): TenantContext|JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->allows(TenantPermissions::ACCESS)) {
            return $this->error(403, 'tenant_context_required', 'Tenant context required.');
        }
        if (!$this->modules->isEnabled($tenant->organizationId()->value(), 'sales')) {
            return $this->error(403, 'sales_module_disabled', 'Sales module is disabled for this organization.');
        }

        $actor = $tenant->userId()->value();
        if (!ctype_digit($actor) || (int) $actor <= 0) {
            return $this->error(403, 'invalid_actor', 'Authenticated actor is invalid.');
        }
        if (!$this->authorization->allows($tenant->organizationId()->value(), (int) $actor, $capability)) {
            return $this->error(403, 'sales_admin_capability_required', 'Required Sales administration capability is missing.');
        }
        if ($mutation && ($request === null || !$this->csrf->isValid($request))) {
            return $this->error(400, 'invalid_csrf_token', 'Invalid CSRF token.');
        }

        return $tenant;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : $request->request->all();
    }

    private function correlationId(Request $request): string
    {
        $value = $request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId ? $value->value() : CorrelationId::generate()->value();
    }

    private function failure(Throwable $error): JsonResponse
    {
        $root = $error instanceof HandlerFailedException && $error->getPrevious() instanceof Throwable
            ? $error->getPrevious()
            : $error;
        $message = $root->getMessage();
        $normalized = strtolower($message);

        $status = match (true) {
            $message === 'CONFIGURATION_CONFLICT',
            str_contains($normalized, 'configuration conflict') => 409,
            str_contains($normalized, 'not found') => 404,
            $root instanceof DomainException => 422,
            default => 500,
        };

        return $this->error($status, 'sales_admin_operation_failed', $message);
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'data' => $data], $status);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
