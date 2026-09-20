<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Sales\Command\SalesFrontendMutationCommand;
use App\Application\Sales\Query\SalesFrontendQuery;
use App\Security\SessionCsrfValidator;
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

final readonly class SalesFrontendController
{
    public function __construct(
        private CommandBusInterface $commands,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $tenant = $this->context(null, false);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        try {
            $data = $this->queries->ask(new SalesFrontendQuery(
                $tenant->organizationId(),
                SalesFrontendQuery::SEARCH,
                [
                    'q' => (string) $request->query->get('q', ''),
                    'limit' => (int) $request->query->get('limit', 6),
                ],
            ));

            return $this->ok($data);
        } catch (Throwable $error) {
            return $this->failure($error);
        }
    }

    public function intelligence(string $id): JsonResponse
    {
        $tenant = $this->context(null, false);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        try {
            return $this->ok($this->queries->ask(new SalesFrontendQuery(
                $tenant->organizationId(),
                SalesFrontendQuery::INTELLIGENCE,
                ['deal_id' => (int) $id],
            )));
        } catch (Throwable $error) {
            return $this->failure($error);
        }
    }

    public function quickUpdate(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::QUICK_EDIT,
            $id,
            $this->input($request),
        );
    }

    public function owner(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::OWNER,
            $id,
            $this->input($request),
        );
    }

    public function meeting(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::MEETING,
            $id,
            $this->input($request),
            true,
            201,
        );
    }

    public function completeActivity(Request $request, string $id, string $activityId): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::COMPLETE_ACTIVITY,
            $id . ':' . $activityId,
            [],
        );
    }

    public function rescheduleActivity(Request $request, string $id, string $activityId): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::RESCHEDULE_ACTIVITY,
            $id . ':' . $activityId,
            $this->input($request),
        );
    }

    public function leadFollowup(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::LEAD_FOLLOWUP,
            $id,
            $this->input($request),
            true,
            201,
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::APPROVAL,
            $id,
            ['decision' => 'approve', ...$this->input($request)],
        );
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::APPROVAL,
            $id,
            ['decision' => 'reject', ...$this->input($request)],
        );
    }

    public function execute(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::ACTION,
            $id,
            ['decision' => 'execute'],
            false,
            202,
        );
    }

    public function dismiss(Request $request, string $id): JsonResponse
    {
        return $this->mutate(
            $request,
            SalesFrontendMutationCommand::ACTION,
            $id,
            ['decision' => 'dismiss'],
        );
    }

    /** @param array<string,mixed> $input */
    private function mutate(
        Request $request,
        string $operation,
        string $resourceId,
        array $input,
        bool $idempotencyRequired = false,
        int $status = 200,
    ): JsonResponse {
        $tenant = $this->context($request, true);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        $idempotencyKey = trim((string) $request->headers->get('X-Idempotency-Key', ''));
        if ($idempotencyRequired && ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191)) {
            return $this->error(422, 'idempotency_key_required', 'A valid X-Idempotency-Key is required.');
        }
        if ($idempotencyKey === '') {
            $idempotencyKey = 'frontend:' . $this->correlationId($request);
        }

        try {
            $data = $this->commands->dispatch(new SalesFrontendMutationCommand(
                $tenant->organizationId(),
                (int) $tenant->userId()->value(),
                $operation,
                $resourceId,
                $input,
                $this->correlationId($request),
                $idempotencyKey,
            ));

            return $this->ok($data, $status);
        } catch (Throwable $error) {
            return $this->failure($error);
        }
    }

    private function context(?Request $request, bool $mutation): TenantContext|JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return $this->error(403, 'tenant_context_required', 'Tenant context required.');
        }
        if (!$tenant->allows(TenantPermissions::ACCESS) || !$tenant->isManager()) {
            return $this->error(403, 'manager_required', 'Sales manager authorization required.');
        }
        if (!$this->modules->isEnabled($tenant->organizationId()->value(), 'sales')) {
            return $this->error(403, 'sales_module_disabled', 'Sales module is disabled for this organization.');
        }
        if ($mutation && ($request === null || !$this->csrf->isValid($request))) {
            return $this->error(400, 'invalid_csrf_token', 'Invalid CSRF token.');
        }

        $actor = $tenant->userId()->value();
        if (!ctype_digit($actor) || (int) $actor <= 0) {
            return $this->error(403, 'invalid_actor', 'Authenticated actor is invalid.');
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
            str_contains($normalized, 'not found'),
            str_contains($normalized, 'does not exist') => 404,
            str_contains($normalized, 'current status'),
            str_contains($normalized, 'requires approval'),
            str_contains($normalized, 'pending approval'),
            str_contains($normalized, 'already'),
            str_contains($normalized, 'idempotency') => 409,
            $root instanceof DomainException => 422,
            default => 500,
        };

        return $this->error($status, 'sales_frontend_operation_failed', $message);
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
