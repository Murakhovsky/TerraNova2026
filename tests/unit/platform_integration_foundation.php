<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\ConnectorInterface;
use Platform\Integration\Contract\ExternalApiClientInterface;
use Platform\Integration\Model\Connection;
use Platform\Integration\Model\ConnectionStatus;
use Platform\Integration\Model\ConnectorDefinition;
use Platform\Integration\Model\Credential;
use Platform\Integration\Service\ConnectorRegistry;

$organizationId = OrganizationId::fromString('org-test');
$definition = new ConnectorDefinition('telegram.bot', 'Telegram Bot', 'telegram', ['messages.send', 'webhooks.receive']);
$connector = new class($definition) implements ConnectorInterface {
    public function __construct(private ConnectorDefinition $definition) {}
    public function definition(): ConnectorDefinition { return $this->definition; }
    public function supports(string $capability): bool { return in_array($capability, $this->definition->capabilities, true); }
    public function client(Connection $connection, Credential $credential): ExternalApiClientInterface { throw new RuntimeException('not needed'); }
};

$registry = new ConnectorRegistry([$connector]);
assert($registry->has('telegram.bot'));
assert($registry->get('telegram.bot')->supports('messages.send'));
assert(count($registry->definitions()) === 1);

$connection = new Connection('conn-1', $organizationId, 'connector-1', ConnectionStatus::ACTIVE, 'cred-1', 'chat-42', [], new DateTimeImmutable(), new DateTimeImmutable());
$credential = new Credential('cred-1', $organizationId, $connection->id, 'bot_token', 'vault://org-test/telegram/cred-1', ['messages:write']);
assert($connection->status->usable());
assert(!$credential->expired(new DateTimeImmutable()));
assert(str_starts_with($credential->secretReference, 'vault://'));

$duplicateRejected = false;
try { $registry->register($connector); } catch (InvalidArgumentException) { $duplicateRejected = true; }
assert($duplicateRejected);

echo "Platform Integration foundation OK\n";
