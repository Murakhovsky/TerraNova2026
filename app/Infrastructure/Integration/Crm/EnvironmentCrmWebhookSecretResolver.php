<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\CrmWebhookSecretResolverInterface;
use PDO;
use RuntimeException;

final readonly class EnvironmentCrmWebhookSecretResolver implements CrmWebhookSecretResolverInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function secretFor(string $organizationId, string $provider): string
    {
        $statement = $this->connection->prepare(
            "SELECT credentials_reference FROM cos_integrations WHERE organization_id = :organization_id "
            . "AND capability = 'CRM' AND provider = :provider AND status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId, 'provider' => $provider]);
        $reference = $statement->fetchColumn();
        if ($reference === false) throw new RuntimeException('CRM integration is not active.');
        $environmentName = is_string($reference) && str_starts_with($reference, 'env:')
            ? substr($reference, 4)
            : 'CRM_WEBHOOK_SECRET_' . strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $organizationId . '_' . $provider));
        $secret = getenv($environmentName);
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('CRM webhook secret is not configured.');
        }
        return $secret;
    }
}
