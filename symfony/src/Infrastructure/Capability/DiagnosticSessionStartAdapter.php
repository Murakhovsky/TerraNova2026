<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Diagnostic\Command\StartDiagnosticCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/**
 * Direct human-only, tenant-owned Diagnostic CommandBus boundary.
 * The canonical Gateway performs schema/permission/module/idempotency checks.
 */
final readonly class DiagnosticSessionStartAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}

    public function binding(): string { return 'command:diagnostic.session.start'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        foreach (['organization_id', 'tenant_id', 'actor_id', 'user_id', 'session_id', 'idempotency_request_hash'] as $forged) {
            if (array_key_exists($forged, $input)) {
                throw new DomainException('Diagnostic identity and session receipts are server-owned.');
            }
        }
        $userId = $actor->userId()->value();
        if (!ctype_digit($userId) || (int) $userId <= 0 || trim($key) === '') {
            throw new DomainException('A valid authenticated actor and idempotency key are required.');
        }
        $raw = $this->commands->dispatch(new StartDiagnosticCommand(
            $actor->organizationId(), (int) $userId, $correlationId, $key, $input,
        ));
        $expectedId = substr(hash('sha256', 'diagnostic:start:' . $actor->organizationId()->value() . ':' . $key), 0, 32);
        if (!is_array($raw) || ($raw['session_id'] ?? null) !== $expectedId
            || !is_string($raw['status'] ?? null) || $raw['status'] === ''
            || ($raw['pack_id'] ?? null) !== ($input['pack_id'] ?? null)
            || !is_int($raw['pack_version'] ?? null) || $raw['pack_version'] < 1
            || !is_bool($raw['replayed'] ?? null)) {
            throw new DomainException('Diagnostic session command returned an unverified native receipt.');
        }
        // Generic Capability inventory never leaks interview contents, target PII,
        // facts, methodology prompts, or the next question from the session snapshot.
        return [
            'session_id' => $expectedId,
            'status' => $raw['status'],
            'pack_id' => $raw['pack_id'],
            'pack_version' => $raw['pack_version'],
            'replayed' => $raw['replayed'],
        ];
    }
}
