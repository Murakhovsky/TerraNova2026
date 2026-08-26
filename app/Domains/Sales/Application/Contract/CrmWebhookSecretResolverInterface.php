<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface CrmWebhookSecretResolverInterface
{
    public function secretFor(string $organizationId, string $provider): string;
}
