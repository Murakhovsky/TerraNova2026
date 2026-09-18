<?php
declare(strict_types=1);

namespace App\Application\Integration\Contract;

interface CrmWebhookCredentialResolverInterface
{
    public function secretFor(int $integrationId, string $organizationId, string $provider): string;
}
