<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence;

interface ExternalReferenceStoreInterface
{
    public function find(string $organizationId, string $provider, string $entityType, string $reference): ?string;

    public function put(string $organizationId, string $provider, string $entityType, string $externalId, string $reference): void;
}
