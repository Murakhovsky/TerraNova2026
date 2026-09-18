<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Application\Integration\Command\ManageSalesIntegrationCommand;
use App\Application\Integration\Command\ManageSalesIntegrationCommandHandler;
use App\Application\Integration\IntegrationMutationAudit;
use DomainException;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final class Wave6FakeIntegrations implements SalesIntegrationAdministrationInterface
{
    public int $creates = 0;

    public function catalog(): array { return []; }
    public function integrations(string $organizationId): array { return []; }
    public function routingOptions(string $organizationId): array { return []; }
    public function integration(string $organizationId, int $integrationId): ?array { return ['id' => $integrationId]; }

    public function create(string $organizationId, array $input, string $actorId): array
    {
        ++$this->creates;
        return [
            'id' => 77,
            'integration_key' => (string) ($input['integration_key'] ?? ''),
            'status' => 'DRAFT',
            'configuration_version' => 1,
        ];
    }

    public function update(string $organizationId, int $integrationId, array $input, int $expectedVersion, string $actorId): array
    {
        return ['id' => $integrationId, 'configuration_version' => $expectedVersion + 1];
    }

    public function testConnection(string $organizationId, int $integrationId): array
    {
        return ['id' => $integrationId, 'health_status' => 'HEALTHY'];
    }

    public function saveRoute(string $organizationId, int $integrationId, array $input, string $actorId): array
    {
        return ['id' => 'route-1', 'integration_id' => $integrationId, 'configuration_version' => 1];
    }

    public function revisions(string $organizationId, int $integrationId, int $limit = 100): array
    {
        return [];
    }
}

final class Wave6FakeReceipts implements SalesMutationReceiptRepositoryInterface
{
    /** @var array<string,string> */
    private array $values = [];

    private function key(string $organizationId, string $operationType, string $idempotencyKey): string
    {
        return $organizationId . '|' . $operationType . '|' . $idempotencyKey;
    }

    public function find(string $organizationId, string $operationType, string $idempotencyKey): ?string
    {
        return $this->values[$this->key($organizationId, $operationType, $idempotencyKey)] ?? null;
    }

    public function claim(string $organizationId, string $operationType, string $idempotencyKey, string $pendingMutationId): bool
    {
        $key = $this->key($organizationId, $operationType, $idempotencyKey);
        if (isset($this->values[$key])) {
            return false;
        }
        $this->values[$key] = $pendingMutationId;
        return true;
    }

    public function complete(
        string $organizationId,
        string $operationType,
        string $idempotencyKey,
        string $pendingMutationId,
        string $mutationId,
    ): bool {
        $key = $this->key($organizationId, $operationType, $idempotencyKey);
        if (($this->values[$key] ?? null) !== $pendingMutationId) {
            return false;
        }
        $this->values[$key] = $mutationId;
        return true;
    }
}

final class Wave6FakeTransactions implements TransactionManagerInterface
{
    public function transactional(callable $operation): mixed { return $operation(); }
    public function isActive(): bool { return false; }
    public function afterCommit(callable $callback): void { $callback(); }
}

final class Wave6FakeAudit implements AuditRepositoryInterface
{
    /** @var list<AuditEntry> */
    public array $entries = [];
    public function append(AuditEntry $entry): void { $this->entries[] = $entry; }
}

$integrations = new Wave6FakeIntegrations();
$receipts = new Wave6FakeReceipts();
$auditRepository = new Wave6FakeAudit();
$handler = new ManageSalesIntegrationCommandHandler(
    $integrations,
    $receipts,
    new Wave6FakeTransactions(),
    new IntegrationMutationAudit($auditRepository),
);

$command = new ManageSalesIntegrationCommand(
    OrganizationId::fromString('default'),
    1001,
    ManageSalesIntegrationCommand::CREATE,
    'wave6-correlation',
    'wave6-create-001',
    null,
    ['integration_key' => 'crm.aida', 'name' => 'AIDA'],
);

$first = $handler($command);
if (($first['replayed'] ?? null) !== false || ($first['id'] ?? null) !== 77 || $integrations->creates !== 1) {
    throw new RuntimeException('Wave 6 initial integration mutation failed.');
}
if (count($auditRepository->entries) !== 1) {
    throw new RuntimeException('Wave 6 integration mutation audit was not recorded.');
}

$replay = $handler($command);
if (($replay['replayed'] ?? null) !== true || $integrations->creates !== 1) {
    throw new RuntimeException('Wave 6 idempotent replay executed the mutation twice.');
}

$conflict = false;
try {
    $handler(new ManageSalesIntegrationCommand(
        OrganizationId::fromString('default'),
        1001,
        ManageSalesIntegrationCommand::CREATE,
        'wave6-correlation-conflict',
        'wave6-create-001',
        null,
        ['integration_key' => 'crm.aida', 'name' => 'Different payload'],
    ));
} catch (DomainException $error) {
    $conflict = str_contains($error->getMessage(), 'different integration mutation payload');
}

if (!$conflict) {
    throw new RuntimeException('Wave 6 idempotency key reuse with a different payload was not rejected.');
}

echo "Sales Wave 6 integration semantic idempotency contract passed.\n";
