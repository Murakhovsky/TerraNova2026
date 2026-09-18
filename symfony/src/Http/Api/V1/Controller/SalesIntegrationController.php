<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Integration\Command\ManageSalesIntegrationCommand;
use App\Application\Integration\Command\ReceiveCrmWebhookCommand;
use App\Application\Integration\Query\SalesIntegrationQuery;
use App\Security\LegacySessionCsrfValidator;
use DomainException;
use InvalidArgumentException;
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

final readonly class SalesIntegrationController
{
    public function __construct(
        private CommandBusInterface $commands,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function catalog(): JsonResponse
    {
        return $this->read(fn (TenantContext $tenant): mixed => $this->queries->ask(new SalesIntegrationQuery(
            $tenant->organizationId(),
            SalesIntegrationQuery::CATALOG,
        )));
    }

    public function integrations(): JsonResponse
    {
        return $this->read(fn (TenantContext $tenant): mixed => $this->queries->ask(new SalesIntegrationQuery(
            $tenant->organizationId(),
            SalesIntegrationQuery::LIST,
        )));
    }

    public function routingOptions(): JsonResponse
    {
        return $this->read(fn (TenantContext $tenant): mixed => $this->queries->ask(new SalesIntegrationQuery(
            $tenant->organizationId(),
            SalesIntegrationQuery::ROUTING_OPTIONS,
        )));
    }

    public function integration(string $id): JsonResponse
    {
        return $this->read(fn (TenantContext $tenant): mixed => $this->queries->ask(new SalesIntegrationQuery(
            $tenant->organizationId(),
            SalesIntegrationQuery::VIEW,
            (int) $id,
        )));
    }

    public function revisions(Request $request, string $id): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query->get('limit', 100)));

        return $this->read(fn (TenantContext $tenant): mixed => $this->queries->ask(new SalesIntegrationQuery(
            $tenant->organizationId(),
            SalesIntegrationQuery::REVISIONS,
            (int) $id,
            $limit,
        )));
    }

    public function create(Request $request): JsonResponse
    {
        return $this->mutate($request, ManageSalesIntegrationCommand::CREATE, null, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->mutate($request, ManageSalesIntegrationCommand::UPDATE, (int) $id);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        return $this->mutate($request, ManageSalesIntegrationCommand::TEST, (int) $id);
    }

    public function route(Request $request, string $id): JsonResponse
    {
        return $this->mutate($request, ManageSalesIntegrationCommand::SAVE_ROUTE, (int) $id);
    }

    public function webhook(Request $request, string $id): JsonResponse
    {
        $raw = (string) $request->getContent();
        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->error(400, 'invalid_json', 'Webhook body must be a JSON object.');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->error(400, 'invalid_json', 'Webhook body must be a JSON object.');
        }

        $externalEventId = trim((string) ($request->headers->get('X-CRM-Event-Id') ?: ($payload['event_id'] ?? '')));
        $eventType = trim((string) ($payload['event_type'] ?? ''));
        $signature = (string) $request->headers->get('X-CRM-Signature', '');

        try {
            $result = $this->commands->dispatch(new ReceiveCrmWebhookCommand(
                (int) $id,
                $externalEventId,
                $eventType,
                $payload,
                $signature,
                $raw,
                $this->correlationId($request),
            ));

            return new JsonResponse(['ok' => true, 'data' => $result], 202);
        } catch (Throwable $error) {
            $root = $this->unwrap($error);
            $message = $root->getMessage();
            if ($root instanceof InvalidArgumentException) {
                return $this->error(401, 'webhook_rejected', $message);
            }
            if ($root instanceof DomainException && str_contains(strtolower($message), 'reused')) {
                return $this->error(409, 'webhook_idempotency_conflict', $message);
            }

            return $this->error(503, 'webhook_unavailable', 'CRM webhook could not be accepted.');
        }
    }

    private function mutate(Request $request, string $action, ?int $integrationId, int $createdStatus = 200): JsonResponse
    {
        $tenant = $this->context($request, true);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        $key = trim((string) $request->headers->get('X-Idempotency-Key', ''));
        if ($key === '' || mb_strlen($key) > 191) {
            return $this->error(422, 'idempotency_key_required', 'A valid X-Idempotency-Key is required.');
        }

        try {
            $result = $this->commands->dispatch(new ManageSalesIntegrationCommand(
                $tenant->organizationId(),
                (int) $tenant->userId()->value(),
                $action,
                $this->correlationId($request),
                $key,
                $integrationId,
                $this->input($request),
            ));

            $status = ($result['replayed'] ?? false) === true ? 200 : $createdStatus;
            return new JsonResponse(['ok' => true, 'data' => $result], $status);
        } catch (Throwable $error) {
            return $this->integrationError($error);
        }
    }

    private function read(callable $operation): JsonResponse
    {
        $tenant = $this->context(null, false);
        if ($tenant instanceof JsonResponse) {
            return $tenant;
        }

        try {
            return new JsonResponse(['ok' => true, 'data' => $operation($tenant)]);
        } catch (Throwable $error) {
            return $this->integrationError($error);
        }
    }

    private function context(?Request $request, bool $mutation): TenantContext|JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return $this->error(403, 'tenant_context_required', 'Tenant context required.');
        }
        if (!$tenant->allows(TenantPermissions::MANAGE)) {
            return $this->error(403, 'permission_denied', 'Tenant management permission required.');
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

    private function integrationError(Throwable $error): JsonResponse
    {
        $root = $this->unwrap($error);
        $message = $root->getMessage();
        $normalized = strtolower($message);

        $status = match (true) {
            $message === 'CONFIGURATION_CONFLICT',
            str_contains($normalized, 'idempotency'),
            str_contains($normalized, 'already in progress') => 409,
            str_contains($normalized, 'not found') => 404,
            $root instanceof DomainException,
            $root instanceof InvalidArgumentException => 422,
            default => 500,
        };

        return $this->error($status, 'integration_operation_failed', $message);
    }

    private function unwrap(Throwable $error): Throwable
    {
        return $error instanceof HandlerFailedException && $error->getPrevious() instanceof Throwable
            ? $error->getPrevious()
            : $error;
    }

    private function correlationId(Request $request): string
    {
        $value = $request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId ? $value->value() : CorrelationId::generate()->value();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
