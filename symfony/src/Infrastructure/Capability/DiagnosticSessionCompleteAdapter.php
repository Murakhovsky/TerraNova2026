<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Diagnostic\Command\CompleteDiagnosticCommand;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Terminal, human-only completion through the existing transactional CommandBus. */
final readonly class DiagnosticSessionCompleteAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(
        private CommandBusInterface $commands,
        private DiagnosticRuntimeRepositoryInterface $runtime,
    ) {}

    public function binding(): string { return 'command:diagnostic.session.complete'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $sessionId = $input['session_id'] ?? null;
        $actorId = $actor->userId()->value();
        if (array_keys($input) !== ['session_id'] || !is_string($sessionId)
            || preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1
            || !ctype_digit($actorId) || (int) $actorId < 1 || trim($key) === '') {
            throw new DomainException('A tenant session, authenticated actor and idempotency key are required.');
        }
        $org = $actor->organizationId()->value();
        // Completion has a natural idempotency identity: tenant + session.
        // A completed session must never be evaluated again, even with another request key.
        $before = $this->runtime->get($org, $sessionId);
        if (!$this->owns($before, $org, $sessionId) || !in_array($before['status'] ?? null, ['active', 'completed'], true)) {
            throw new DomainException('Diagnostic session is not available for completion.');
        }
        $raw = $this->commands->dispatch(new CompleteDiagnosticCommand(
            $actor->organizationId(), (int) $actorId, $sessionId, $correlationId,
        ));
        // Independent native readback, not a report copied from the command receipt.
        $after = $this->runtime->get($org, $sessionId);
        $report = $this->runtime->report($org, $sessionId);
        $version = $this->version($report['report_version'] ?? null);
        if (!is_array($raw) || !is_bool($raw['replayed'] ?? null)
            || !$this->owns($after, $org, $sessionId) || ($after['status'] ?? null) !== 'completed'
            || $version < 1 || $this->version($raw['report_version'] ?? null) !== $version
            || (($before['status'] ?? null) === 'completed' && !$raw['replayed'])) {
            throw new DomainException('Diagnostic completion could not be verified by native readback.');
        }
        return ['session_id' => $sessionId, 'status' => 'completed', 'report_version' => $version, 'replayed' => $raw['replayed']];
    }

    private function owns(?array $row, string $org, string $session): bool
    {
        return $row !== null && ($row['organization_id'] ?? null) === $org && ($row['session_id'] ?? null) === $session;
    }

    private function version(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }
}
