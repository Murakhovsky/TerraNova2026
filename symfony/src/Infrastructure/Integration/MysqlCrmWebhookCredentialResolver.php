<?php
declare(strict_types=1);

namespace App\Infrastructure\Integration;

use App\Application\Integration\Contract\CrmWebhookCredentialResolverInterface;
use PDO;
use RuntimeException;

final readonly class MysqlCrmWebhookCredentialResolver implements CrmWebhookCredentialResolverInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function secretFor(int $integrationId, string $organizationId, string $provider): string
    {
        $statement = $this->connection->prepare(
            'SELECT credentials_reference FROM cos_integrations '
            . 'WHERE id=:id AND organization_id=:organization_id AND capability="CRM" '
            . 'AND provider=:provider AND status="ACTIVE" LIMIT 1'
        );
        $statement->execute([
            'id' => $integrationId,
            'organization_id' => $organizationId,
            'provider' => $provider,
        ]);
        $reference = $statement->fetchColumn();

        if ($reference === false) {
            throw new RuntimeException('CRM integration is not active.');
        }

        $environmentName = is_string($reference) && str_starts_with($reference, 'env:')
            ? substr($reference, 4)
            : 'CRM_WEBHOOK_SECRET_' . strtoupper(
                preg_replace('/[^A-Z0-9]+/i', '_', $organizationId . '_' . $provider)
            );

        $secret = getenv($environmentName);
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('CRM webhook secret is not configured.');
        }

        return $secret;
    }
}
