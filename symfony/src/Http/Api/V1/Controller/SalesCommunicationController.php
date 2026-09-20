<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Integration\Command\SendSalesCommunicationCommand;
use App\Security\SessionCsrfValidator;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class SalesCommunicationController
{
    public function __construct(
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return $this->error(403, 'tenant_context_required', 'Tenant context required.');
        }
        if (!$tenant->allows(TenantPermissions::ACCESS)) {
            return $this->error(403, 'permission_denied', 'Tenant access permission required.');
        }
        if (!$this->modules->isEnabled($tenant->organizationId()->value(), 'sales')) {
            return $this->error(403, 'sales_module_disabled', 'Sales module is disabled for this organization.');
        }
        if (!$this->csrf->isValid($request)) {
            return $this->error(400, 'invalid_csrf_token', 'Invalid CSRF token.');
        }

        $actor = $tenant->userId()->value();
        if (!ctype_digit($actor) || (int) $actor <= 0) {
            return $this->error(403, 'invalid_actor', 'Authenticated actor is invalid.');
        }

        $key = trim((string) $request->headers->get('X-Idempotency-Key', ''));
        if ($key === '' || mb_strlen($key) > 191) {
            return $this->error(422, 'idempotency_key_required', 'A valid X-Idempotency-Key is required.');
        }

        $input = $this->input($request);
        $channel = strtoupper(trim((string) ($input['channel'] ?? '')));
        $body = trim((string) ($input['body'] ?? ''));
        if (!in_array($channel, SendSalesCommunicationCommand::SUPPORTED_CHANNELS, true)) {
            return $this->error(422, 'invalid_communication_channel', 'Unsupported communication channel.');
        }
        if ($body === '' || mb_strlen($body) > 4000) {
            return $this->error(422, 'invalid_communication_body', 'body is required and must not exceed 4000 characters.');
        }

        $correlation = $request->attributes->get('_cos_correlation_id');
        $correlationId = $correlation instanceof CorrelationId
            ? $correlation->value()
            : CorrelationId::generate()->value();

        $this->commands->dispatch(new SendSalesCommunicationCommand(
            $tenant->organizationId(),
            (int) $actor,
            (int) $id,
            $channel,
            $body,
            $key,
            $correlationId,
        ));

        return new JsonResponse([
            'ok' => true,
            'data' => [
                'accepted' => true,
                'correlation_id' => $correlationId,
            ],
        ], 202);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : $request->request->all();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
