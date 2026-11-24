<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Application\Diagnostic\Command\CaptureDiagnosticEvidenceCommand;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Model\TenantContext;

/** Human-only Diagnostic evidence boundary; Domain CommandBus remains the sole writer. */
final readonly class DiagnosticEvidenceCaptureAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private CommandBusInterface $commands) {}

    public function binding(): string { return 'command:diagnostic.evidence.capture'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $sessionId = $input['session_id'] ?? null;
        $actorId = $actor->userId()->value();
        if (!is_string($sessionId) || preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1
            || !ctype_digit($actorId) || (int) $actorId < 1 || trim($key) === '') {
            throw new DomainException('A session, authenticated actor and idempotency key are required.');
        }
        $payload = $input;
        unset($payload['session_id']);
        $organization = $actor->organizationId()->value();
        $raw = $this->commands->dispatch(new CaptureDiagnosticEvidenceCommand(
            $actor->organizationId(), (int) $actorId, $sessionId, $correlationId, $key, $payload
        ));
        $expectedId = hash('sha256', $organization . ':' . $sessionId . ':' . $key);
        if (!is_array($raw) || ($raw['evidence_id'] ?? null) !== $expectedId
            || !is_bool($raw['replayed'] ?? null)
            || !is_int($raw['facts_ingested'] ?? null) || $raw['facts_ingested'] < 0
            || !is_int($raw['metrics_ingested'] ?? null) || $raw['metrics_ingested'] < 0
            || $raw['facts_ingested'] > count($payload['facts'] ?? [])
            || $raw['metrics_ingested'] > count($payload['metrics'] ?? [])
            || ($raw['replayed'] && ($raw['facts_ingested'] !== 0 || $raw['metrics_ingested'] !== 0))
            || (!$raw['replayed'] && (!is_int($raw['revision'] ?? null) || $raw['revision'] < 1))) {
            throw new DomainException('Diagnostic evidence command returned an invalid native receipt.');
        }
        $receipt = [
            'evidence_id' => $expectedId,
            'replayed' => $raw['replayed'],
            'facts_ingested' => $raw['facts_ingested'],
            'metrics_ingested' => $raw['metrics_ingested'],
        ];
        if (!$raw['replayed']) $receipt['revision'] = $raw['revision'];
        return $receipt;
    }
}
